<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Customers\ManageCustomerInstrumentAction;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ActiveTenantContext;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Sandbox\SandboxContext;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** FC-07 Chèques & effets à recevoir: incoming customer instruments (portfolio, deposits, cashed, rejected, due dates). Mutations re-check finance.customers.instruments.manage in the action. */
class CustomerInstrumentsController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard): Response
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $filters = $request->validate(['state' => ['nullable', Rule::in(['received', 'in_portfolio', 'submitted', 'cleared', 'rejected', 'cancelled'])], 'search' => ['nullable', 'string', 'max:100'], 'customer_id' => ['nullable', 'integer']]);
        $query = DB::table('customer_payment_instruments as i')->join('customers as c', 'c.id', '=', 'i.customer_id')->where('i.organization_id', $organization->getKey())
            ->when($filters['state'] ?? null, fn ($q, $s) => $q->where('i.state', $s))->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('i.customer_id', $id))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($q) => $q->where('i.instrument_number', 'like', "%{$s}%")->orWhere('i.customer_reference', 'like', "%{$s}%")->orWhere('c.display_name', 'like', "%{$s}%")));
        $page = (clone $query)->orderByRaw('CASE WHEN i.maturity_date IS NULL THEN 1 ELSE 0 END')->orderBy('i.maturity_date')->orderByDesc('i.id')
            ->select(['i.id', 'i.type', 'i.instrument_number', 'i.customer_reference', 'i.issuing_bank', 'i.amount', 'i.currency_code', 'i.received_date', 'i.expected_collection_date', 'i.maturity_date',
                'i.state', 'i.customer_id', 'i.sales_order_id', 'i.cleared_payment_id', 'i.store_id', 'c.display_name as customer', 'i.financial_account_id'])
            ->paginate(25)->withQueryString();
        $totals = DB::table('customer_payment_instruments')->where('organization_id', $organization->getKey())->groupBy('state', 'currency_code')->select('state', 'currency_code')
            ->selectRaw('ROUND(SUM(amount), 4) as total')->selectRaw('COUNT(*) as n')->get()->map(fn ($r) => ['state' => $r->state, 'currency_code' => $r->currency_code, 'total' => Decimal::normalize((string) $r->total), 'count' => (int) $r->n]);
        $canManage = $request->user()->hasPermission($organization, 'finance.customers.instruments.manage');

        return Inertia::render('Finance/Clients/Instruments', [
            'organization' => $organization->only(['id', 'name']), 'instruments' => $page, 'filters' => array_filter($filters), 'totals' => $totals,
            'events' => DB::table('customer_instrument_events')->where('organization_id', $organization->getKey())->whereIn('instrument_id', $page->pluck('id'))->orderBy('id')->get(['instrument_id', 'from_state', 'to_state', 'event_date', 'note']),
            'customers' => $canManage ? Customer::query()->where('organization_id', $organization->getKey())->orderBy('display_name')->limit(1000)->get(['id', 'display_name']) : [],
            'accounts' => $canManage ? DB::table('financial_accounts')->where('organization_id', $organization->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'currency_code']) : [],
            'can' => ['manage' => $canManage],
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
}
