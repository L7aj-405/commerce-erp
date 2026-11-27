<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Customers\ManageCustomerInstrumentAction;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Finance\Export\TabularExport;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Sandbox\SandboxContext;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FC-07 Chèques & effets à recevoir: incoming customer instruments (portfolio, deposits, cashed, rejected, due dates). Mutations re-check finance.customers.instruments.manage in the action. */
class CustomerInstrumentsController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard): Response
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        [$query, $filters] = $this->filtered($request, $organization->getKey());
        $page = (clone $query)->orderByRaw('CASE WHEN i.maturity_date IS NULL THEN 1 ELSE 0 END')->orderBy('i.maturity_date')->orderByDesc('i.id')
            ->select(['i.id', 'i.type', 'i.instrument_number', 'i.customer_reference', 'i.issuing_bank', 'i.amount', 'i.currency_code', 'i.received_date', 'i.expected_collection_date', 'i.maturity_date',
                'i.state', 'i.customer_id', 'i.sales_order_id', 'i.cleared_payment_id', 'i.store_id', 'c.display_name as customer', 'i.financial_account_id'])
            ->paginate(25)->withQueryString();
        $totals = (clone $query)->reorder()->groupBy('i.state', 'i.currency_code')->select('i.state', 'i.currency_code')
            ->selectRaw('ROUND(SUM(i.amount), 4) as total')->selectRaw('COUNT(*) as n')->get()
            ->map(fn ($r) => ['state' => $r->state, 'currency_code' => $r->currency_code, 'total' => Decimal::normalize((string) $r->total), 'count' => (int) $r->n]);
        $canManage = $request->user()->hasPermission($organization, 'finance.customers.instruments.manage');

        return Inertia::render('Finance/Clients/Instruments', [
            'organization' => $organization->only(['id', 'name']), 'instruments' => $page, 'filters' => array_filter($filters), 'totals' => $totals,
            'events' => DB::table('customer_instrument_events')->where('organization_id', $organization->getKey())->whereIn('instrument_id', $page->pluck('id'))->orderBy('id')->get(['instrument_id', 'from_state', 'to_state', 'event_date', 'note']),
            'customers' => $canManage ? Customer::query()->where('organization_id', $organization->getKey())->orderBy('display_name')->limit(1000)->get(['id', 'display_name']) : [],
            'accounts' => $canManage ? DB::table('financial_accounts')->where('organization_id', $organization->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'currency_code']) : [],
            'can' => ['manage' => $canManage, 'export' => $request->user()->hasPermission($organization, 'finance.export')],
            'group' => $filters['group'],
        ]);
    }

    public function store(Request $request, ManageCustomerInstrumentAction $action): RedirectResponse
    {
        $organization = app(ActiveTenantContext::class)->organizationOrFail();
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'customer_id' => ['required', 'integer'], 'type' => ['required', Rule::in(['cheque', 'effet'])], 'instrument_number' => ['required', 'string', 'max:80'],
            'customer_reference' => ['nullable', 'string', 'max:120'], 'issuing_bank' => ['nullable', 'string', 'max:120'], 'amount' => ['required', 'decimal:0,4'], 'received_date' => ['required', 'date_format:Y-m-d'],
            'expected_collection_date' => ['nullable', 'date_format:Y-m-d'], 'maturity_date' => ['nullable', 'date_format:Y-m-d'], 'financial_account_id' => ['required', 'integer'],
            'sales_order_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $customer = Customer::query()->where('organization_id', $organization->getKey())->findOrFail($data['customer_id']);
        $action->receive($request->user(), $customer, $data, $data['idempotency_key']);

        return back()->with('success', 'Instrument enregistré (aucun mouvement d’argent tant qu’il n’est pas compensé).');
    }

    public function transition(Request $request, int $instrument, ManageCustomerInstrumentAction $action): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', Rule::in(['in_portfolio', 'submitted', 'cleared', 'rejected', 'cancelled'])], 'date' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:2000']]);
        $action->transition($request->user(), $instrument, $data['to'], $data['date'], $data['note'] ?? null);

        return back()->with('success', 'État de l’instrument mis à jour.');
    }

    /** Sub-status groups of the register: applied server-side, before pagination, to the whole organization dataset. */
    private const GROUPS = [
        'portfolio' => ['received', 'in_portfolio'], 'deposits' => ['submitted'], 'cashed' => ['cleared'], 'rejected' => ['rejected', 'cancelled'], 'dues' => ['received', 'in_portfolio', 'submitted'],
    ];

    /** @return array{0: Builder, 1: array<string, mixed>} the filtered query (shared by page, totals and export) and the normalized filters */
    private function filtered(Request $request, int $organizationId): array
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['received', 'in_portfolio', 'submitted', 'cleared', 'rejected', 'cancelled'])], 'search' => ['nullable', 'string', 'max:100'], 'customer_id' => ['nullable', 'integer'],
            'section' => ['nullable', Rule::in(array_keys(self::GROUPS))], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'store_id' => ['nullable', 'integer'],
        ]);
        $group = $filters['section'] ?? 'portfolio';
        // An explicit single state wins over the group; otherwise the group's states apply.
        $states = isset($filters['state']) ? [$filters['state']] : self::GROUPS[$group];
        $query = DB::table('customer_payment_instruments as i')->join('customers as c', 'c.id', '=', 'i.customer_id')->where('i.organization_id', $organizationId)
            ->whereIn('i.state', $states)
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('i.customer_id', $id))
            ->when($filters['store_id'] ?? null, fn ($q, $id) => $q->where('i.store_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('i.received_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('i.received_date', '<=', $d))
            ->when($filters['search'] ?? null, function ($q, string $s) {
                $like = '%'.addcslashes($s, '\\%_').'%';

                return $q->where(fn ($q) => $q->where('i.instrument_number', 'like', $like)->orWhere('i.customer_reference', 'like', $like)->orWhere('c.display_name', 'like', $like));
            });

        return [$query, array_filter($filters + ['group' => $group], fn ($v) => $v !== null && $v !== '')];
    }

    /** Export of the SAME filtered dataset as the page (all pages), finance.export, audited. */
    public function export(Request $request, string $format, ActiveTenantContext $context, FinanceAccessGuard $guard, AuditLogger $audit): StreamedResponse
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $guard->authorizeExport($request->user(), $organization);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        [$query, $filters] = $this->filtered($request, $organization->getKey());
        $audit->record('finance_clients.instruments_exported', $request->user(), $organization, null, null, newValues: ['format' => $format, 'filters' => $filters]);
        $headers = ['Client', 'Type', 'Numéro', 'Banque', 'Date de réception', 'Échéance', 'Encaissement attendu', 'Montant', 'Devise', 'État'];
        $lines = (function () use ($query) {
            foreach ((clone $query)->orderBy('i.id')->select(['i.id', 'c.display_name as customer', 'i.type', 'i.instrument_number', 'i.issuing_bank', 'i.received_date', 'i.maturity_date', 'i.expected_collection_date', 'i.amount', 'i.currency_code', 'i.state'])
                ->lazyById(500, 'i.id', 'id') as $r) {
                yield [$r->customer, $r->type, $r->instrument_number, $r->issuing_bank, substr((string) $r->received_date, 0, 10), $r->maturity_date ? substr((string) $r->maturity_date, 0, 10) : null,
                    $r->expected_collection_date ? substr((string) $r->expected_collection_date, 0, 10) : null, Decimal::normalize((string) $r->amount), $r->currency_code, $r->state];
            }
        })();
        $name = 'instruments-clients-'.now()->format('Ymd');

        return $format === 'csv' ? TabularExport::csv($name, $headers, $lines) : TabularExport::xlsx($name, 'Instruments clients', $headers, $lines, [7]);
    }
}
