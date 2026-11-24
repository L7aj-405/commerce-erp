<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\DocumentVisibility;
use App\Services\Finance\CustomerOpenOrders;
use App\Services\Finance\Export\TabularExport;
use App\Services\Finance\FinanceAccessGuard;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance clients - "Encaissements & instruments > Avances": the register of customer advances.
 *
 * READ-ONLY over the Phase 2 data. An advance is a customer Payment (payments.customer_id is set only by the customer
 * settlement flow); its figures are derived from the real records, never stored twice:
 *   allocated = SUM(payment_allocations) , opening = SUM(active opening-balance settlements) , remaining = amount - allocated - opening.
 * Every mutation (allocate / release / settle an opening debt) keeps going through the existing routes and
 * ManageCustomerAdvanceAction - this controller creates no payment and moves no money.
 * Scope: payments the viewer may see (DocumentVisibility), exactly like the customer account's balances.
 */
class CustomerAdvancesController extends Controller
{
    private const PER_PAGE = 25;

    private const STATUSES = ['available', 'partial', 'allocated', 'reversed'];

    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, DocumentVisibility $visibility, CustomerOpenOrders $lookups): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        $guard->authorizeView($user, $organization);
        abort_unless($visibility->canView($user, $organization, Payment::class), 403);
        $filters = $this->filters($request);

        $page = $this->query($visibility, $user, $organization, $filters)->orderByDesc('adv.payment_date')->orderByDesc('adv.id')->paginate(self::PER_PAGE)->withQueryString();
        $rows = collect($page->items());
        $ids = $rows->pluck('id')->all();
        $allocations = DB::table('payment_allocations as a')->join('sales_orders as o', 'o.id', '=', 'a.sales_order_id')->where('a.organization_id', $organization->getKey())->whereIn('a.payment_id', $ids ?: [0])
            ->orderBy('a.id')->get(['a.payment_id', 'a.sales_order_id', 'o.order_number', 'a.amount', 'a.effective_date', 'a.created_at'])->groupBy('payment_id');
        $releases = DB::table('payment_allocation_releases as r')->join('sales_orders as o', 'o.id', '=', 'r.sales_order_id')->where('r.organization_id', $organization->getKey())->whereIn('r.payment_id', $ids ?: [0])
            ->orderBy('r.id')->get(['r.payment_id', 'o.order_number', 'r.amount', 'r.effective_date', 'r.released_date', 'r.reason'])->groupBy('payment_id');
        $openings = DB::table('customer_opening_balance_settlements as s')->join('customer_opening_balances as b', 'b.id', '=', 's.opening_balance_id')->where('s.organization_id', $organization->getKey())->whereIn('s.payment_id', $ids ?: [0])
            ->orderBy('s.id')->get(['s.id', 's.payment_id', 'b.reference', 's.amount', 's.effective_date', 's.released_date', 's.release_reason'])->groupBy('payment_id');

        // Allocation helpers (open orders / opening debts) only for rows that can still be allocated and only for customers on this page.
        $canManage = $user->hasPermission($organization, 'finance.customers.settlements.manage') && $user->active_store_id !== null;
        $helpers = [];
        if ($canManage) {
            $customers = Customer::query()->where('organization_id', $organization->getKey())->whereIn('id', $rows->filter(fn ($r) => $r->state === 'available' || $r->state === 'partial')->pluck('customer_id')->unique()->all() ?: [0])->get()->keyBy('id');
            foreach ($customers as $customer) {
                $helpers[$customer->getKey()] = ['orders' => $lookups->forCustomer($customer, $user), 'openings' => $lookups->openingTargets($organization->getKey(), $customer->getKey())];
            }
        }

        return Inertia::render('Finance/Clients/Advances', [
            'organization' => $organization->only(['id', 'name']),
            'advances' => [
                'data' => $rows->map(fn ($r) => $this->present($r, $allocations->get($r->id, collect()), $releases->get($r->id, collect()), $openings->get($r->id, collect())))->values(),
                'links' => $page->linkCollection()->toArray(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'helpers' => (object) $helpers,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'stores' => Store::query()->where('organization_id', $organization->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code'])->toArray(),
            'activeStoreId' => $user->active_store_id,
            'can' => [
                'allocate' => $canManage && $user->hasPermission($organization, 'payments.create'),
                'accounts' => $user->hasPermission($organization, 'customers.view'),
                'export' => $user->hasPermission($organization, 'finance.export'),
                'payments' => $user->hasPermission($organization, 'payments.view'),
            ],
        ]);
    }

    public function export(Request $request, string $format, ActiveTenantContext $context, FinanceAccessGuard $guard, DocumentVisibility $visibility, AuditLogger $audit): StreamedResponse
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        $guard->authorizeExport($user, $organization);
        $guard->authorizeView($user, $organization);
        abort_unless($visibility->canView($user, $organization, Payment::class), 403);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $filters = $this->filters($request);
        $query = $this->query($visibility, $user, $organization, $filters)->orderByDesc('adv.payment_date')->orderByDesc('adv.id');
        $audit->record('finance_clients.advances_exported', $user, $organization, null, null, newValues: ['format' => $format, 'filters' => $filters]);
        $headers = ['Référence', 'Client', 'Date de réception', 'Compte financier', 'Mode', 'Montant reçu', 'Affecté', 'Affecté aux soldes d’ouverture', 'Reste disponible', 'Devise', 'Magasin', 'Statut'];
        $lines = (function () use ($query) {
            foreach ($query->lazyById(500, 'adv.id', 'id') as $r) {
                yield [$r->payment_number, $r->customer_name, substr((string) $r->payment_date, 0, 10), $r->account_name, $r->method, Decimal::normalize((string) $r->amount), Decimal::normalize((string) $r->allocated),
                    Decimal::normalize((string) $r->opening_settled), $r->state === 'reversed' ? '0.0000' : Decimal::normalize((string) $r->remaining), $r->currency_code, $r->store_name, $r->state];
            }
        })();
        $name = 'avances-clients-'.now()->format('Ymd');

        return $format === 'csv' ? TabularExport::csv($name, $headers, $lines) : TabularExport::xlsx($name, 'Avances clients', $headers, $lines, [5, 6, 7, 8]);
    }

    /** @return array{q:string, customer_id:?int, store_id:?int, from:?string, to:?string, status:?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'customer_id' => ['nullable', 'integer'], 'store_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
        ]);

        return ['q' => trim((string) ($data['q'] ?? '')), 'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null, 'store_id' => isset($data['store_id']) ? (int) $data['store_id'] : null,
            'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'status' => $data['status'] ?? null];
    }

    /** One row per customer advance, with its derived figures and status; scoped to the viewer's visible payments. */
    private function query(DocumentVisibility $visibility, $user, Organization $organization, array $f): QueryBuilder
    {
        $rem = 'ROUND(payments.amount - COALESCE((SELECT SUM(a.amount) FROM payment_allocations a WHERE a.organization_id = payments.organization_id AND a.payment_id = payments.id), 0) - COALESCE((SELECT SUM(o.amount) FROM customer_opening_balance_settlements o WHERE o.organization_id = payments.organization_id AND o.payment_id = payments.id AND o.released_date IS NULL), 0), 4)';
        $inner = $visibility->visible($user, $organization, Payment::class)->toBase()
            ->join('customers as c', function ($j) {
                $j->on('c.id', '=', 'payments.customer_id')->on('c.organization_id', '=', 'payments.organization_id');
            })
            ->join('financial_accounts as fa', 'fa.id', '=', 'payments.financial_account_id')->join('stores as s', 's.id', '=', 'payments.store_id')
            ->whereNotNull('payments.customer_id')->whereIn('payments.status', ['posted', 'reversed'])
            ->select('payments.id', 'payments.payment_number', 'payments.payment_date', 'payments.method', 'payments.amount', 'payments.currency_code', 'payments.customer_id', 'payments.store_id',
                'c.display_name as customer_name', 'fa.name as account_name', 's.name as store_name')
            ->selectRaw('COALESCE((SELECT SUM(a.amount) FROM payment_allocations a WHERE a.organization_id = payments.organization_id AND a.payment_id = payments.id), 0) as allocated')
            ->selectRaw('COALESCE((SELECT SUM(o.amount) FROM customer_opening_balance_settlements o WHERE o.organization_id = payments.organization_id AND o.payment_id = payments.id AND o.released_date IS NULL), 0) as opening_settled')
            ->selectRaw($rem.' as remaining')
            ->selectRaw("CASE WHEN payments.status = 'reversed' THEN 'reversed' WHEN ".$rem." <= 0 THEN 'allocated' WHEN COALESCE((SELECT SUM(a.amount) FROM payment_allocations a WHERE a.organization_id = payments.organization_id AND a.payment_id = payments.id), 0) + COALESCE((SELECT SUM(o.amount) FROM customer_opening_balance_settlements o WHERE o.organization_id = payments.organization_id AND o.payment_id = payments.id AND o.released_date IS NULL), 0) > 0 THEN 'partial' ELSE 'available' END as state");

        if ($f['q'] !== '') {
            $like = '%'.addcslashes($f['q'], '\\%_').'%';
            $inner->where(fn ($w) => $w->where('payments.payment_number', 'like', $like)->orWhere('c.display_name', 'like', $like)->orWhere('payments.reference', 'like', $like));
        }
        $f['customer_id'] && $inner->where('payments.customer_id', $f['customer_id']);
        $f['store_id'] && $inner->where('payments.store_id', $f['store_id']);
        $f['from'] && $inner->whereDate('payments.payment_date', '>=', $f['from']);
        $f['to'] && $inner->whereDate('payments.payment_date', '<=', $f['to']);

        return DB::query()->fromSub($inner, 'adv')->select('adv.*')->when($f['status'], fn ($q, $status) => $q->where('adv.state', $status));
    }

    /** @return array<string, mixed> */
    private function present(object $r, $allocations, $releases, $openings): array
    {
        return [
            'id' => (int) $r->id, 'payment_number' => $r->payment_number, 'payment_date' => substr((string) $r->payment_date, 0, 10), 'method' => $r->method,
            'amount' => Decimal::normalize((string) $r->amount), 'allocated' => Decimal::normalize((string) $r->allocated), 'opening_settled' => Decimal::normalize((string) $r->opening_settled),
            'remaining' => $r->state === 'reversed' ? '0.0000' : Decimal::normalize((string) $r->remaining), 'currency_code' => $r->currency_code, 'state' => $r->state,
            'customer_id' => (int) $r->customer_id, 'customer_name' => $r->customer_name, 'account_name' => $r->account_name, 'store_id' => (int) $r->store_id, 'store_name' => $r->store_name,
            'allocations' => $allocations->map(fn ($a) => ['order_id' => (int) $a->sales_order_id, 'order_number' => $a->order_number, 'amount' => Decimal::normalize((string) $a->amount),
                'effective_date' => $a->effective_date ? substr((string) $a->effective_date, 0, 10) : substr((string) $a->created_at, 0, 10)])->values(),
            'releases' => $releases->map(fn ($a) => ['order_number' => $a->order_number, 'amount' => Decimal::normalize((string) $a->amount), 'effective_date' => substr((string) $a->effective_date, 0, 10),
                'released_date' => substr((string) $a->released_date, 0, 10), 'reason' => $a->reason])->values(),
            'openings' => $openings->map(fn ($a) => ['id' => (int) $a->id, 'reference' => $a->reference, 'amount' => Decimal::normalize((string) $a->amount), 'effective_date' => substr((string) $a->effective_date, 0, 10),
                'released_date' => $a->released_date ? substr((string) $a->released_date, 0, 10) : null, 'reason' => $a->release_reason])->values(),
        ];
    }
}
