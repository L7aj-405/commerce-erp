<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\Commissions\CommissionEntryLifecycleService;
use App\Services\Commissions\CommissionLedgerReportService;
use App\Services\Commissions\CommissionReconciliationService;
use App\Services\Security\FreshAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionLedgerController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, CommissionLedgerReportService $report, CommissionReconciliationService $reconciliation): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.ledger.view');
        $filters = $this->filters($request, $organization);
        $store = $this->store($organization, $filters['store_id']);
        $salespersonId = $this->salesperson($organization, $filters['salesperson_id']);

        $entries = CommissionEntry::query()
            ->where('organization_id', $organization->getKey())
            ->with(['salesOrder:id,order_number', 'sourceEntry:id,commission_amount', 'customerReturn:id,return_number'])
            ->whereBetween('occurred_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59'])
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->when($salespersonId !== null, fn ($query) => $query->where('salesperson_id', $salespersonId))
            ->when($filters['status'], fn ($query, $value) => $query->where('status', $value))
            ->when($filters['entry_type'], fn ($query, $value) => $query->where('entry_type', $value))
            ->when($filters['order'], fn ($query, $value) => $query->whereHas('salesOrder', fn ($orders) => $orders->where('order_number', 'like', '%'.$value.'%')))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(40)->withQueryString();

        $canReconcile = $request->user()->hasPermission($organization, 'commissions.ledger.reconcile');

        return Inertia::render('Finance/CommissionLedger', [
            'organization' => $organization->only(['id', 'name']),
            'entries' => $entries,
            'filters' => $filters,
            'stores' => Store::query()->where('organization_id', $organization->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'salespeople' => $this->salespeople($organization),
            'summary' => $report->summary($organization, $filters['from'], $filters['to'], $store, $salespersonId),
            'reconciliation' => $canReconcile ? $reconciliation->scan($organization, $filters['from'], $filters['to'], $store, $salespersonId) : null,
            'can' => [
                'approve' => $request->user()->hasPermission($organization, 'commissions.ledger.approve'),
                'markPaid' => $request->user()->hasPermission($organization, 'commissions.ledger.mark_paid'),
                'reconcile' => $canReconcile,
            ],
        ]);
    }

    public function show(Request $request, ActiveTenantContext $context, CommissionEntry $commissionEntry): Response
    {
        $organization = $context->organizationOrFail();
        $this->assertTenant($organization, $commissionEntry);
        $this->authorizePermission($request, $organization, 'commissions.ledger.view');
        $commissionEntry->load([
            'salesOrder:id,order_number', 'salesOrderLine:id,product_name,description,reference,sku',
            'sourceEntry', 'adjustments', 'customerReturn:id,return_number', 'approvedBy:id,name', 'paidBy:id,name',
        ]);

        return Inertia::render('Finance/CommissionEntryShow', [
            'entry' => $commissionEntry,
            'can' => [
                'approve' => $request->user()->hasPermission($organization, 'commissions.ledger.approve'),
                'markPaid' => $request->user()->hasPermission($organization, 'commissions.ledger.mark_paid'),
            ],
        ]);
    }

    public function approve(Request $request, ActiveTenantContext $context, CommissionEntryLifecycleService $lifecycle): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.ledger.approve');
        $data = $request->validate(['entry_ids' => ['required', 'array', 'min:1', 'max:500'], 'entry_ids.*' => ['required', 'integer', 'distinct']]);
        $result = $lifecycle->approve($request->user(), $organization, $data['entry_ids']);

        return back()->with('success', $result['count'].' écriture(s) approuvée(s).');
    }

    public function markPaid(Request $request, ActiveTenantContext $context, CommissionEntryLifecycleService $lifecycle, FreshAuthentication $fresh): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.ledger.mark_paid');
        $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT);
        $data = $request->validate(['entry_ids' => ['required', 'array', 'min:1', 'max:500'], 'entry_ids.*' => ['required', 'integer', 'distinct']]);
        $result = $lifecycle->markPaid($request->user(), $organization, $data['entry_ids']);

        return back()->with('success', $result['count'].' écriture(s) marquée(s) comme payée(s).');
    }

    public function reconcile(Request $request, ActiveTenantContext $context, CommissionReconciliationService $reconciliation, FreshAuthentication $fresh): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.ledger.reconcile');
        $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT);
        $data = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer'], 'salesperson_id' => ['nullable', 'integer'],
        ]);
        $result = $reconciliation->generate(
            $request->user(), $organization, $data['from'], $data['to'],
            $this->store($organization, $data['store_id'] ?? null),
            $this->salesperson($organization, $data['salesperson_id'] ?? null),
        );

        return back()->with('success', "Réconciliation terminée : {$result['created']} écriture(s) créée(s), {$result['existing']} déjà présente(s).");
    }

    /** @return array{from:string,to:string,store_id:?int,salesperson_id:?int,status:?string,entry_type:?string,order:?string} */
    private function filters(Request $request, Organization $organization): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer'], 'salesperson_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,approved,paid'],
            'entry_type' => ['nullable', 'in:sale,return_reversal,correction,cancellation,manual_adjustment'],
            'order' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'from' => $data['from'] ?? now()->startOfYear()->toDateString(),
            'to' => $data['to'] ?? now()->toDateString(),
            'store_id' => isset($data['store_id']) ? (int) $data['store_id'] : null,
            'salesperson_id' => isset($data['salesperson_id']) ? (int) $data['salesperson_id'] : null,
            'status' => $data['status'] ?? null,
            'entry_type' => $data['entry_type'] ?? null,
            'order' => isset($data['order']) ? trim($data['order']) : null,
        ];
    }

    private function store(Organization $organization, int|string|null $id): ?Store
    {
        return empty($id) ? null : Store::query()->where('organization_id', $organization->getKey())->whereKey($id)->firstOrFail();
    }

    private function salesperson(Organization $organization, int|string|null $id): ?int
    {
        if (empty($id)) return null;
        OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $id)->where('status', 'active')->firstOrFail();
        return (int) $id;
    }

    private function salespeople(Organization $organization)
    {
        return OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('status', 'active')
            ->with('user:id,name')->get()->pluck('user')->filter()->unique('id')->sortBy('name')->values();
    }

    private function authorizePermission(Request $request, Organization $organization, string $permission): void
    {
        abort_unless($request->user()->hasPermission($organization, $permission), 403);
    }

    private function assertTenant(Organization $organization, CommissionEntry $entry): void
    {
        abort_unless((int) $entry->organization_id === (int) $organization->getKey(), 404);
    }
}
