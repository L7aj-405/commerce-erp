<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Customers\ManageCustomerAdvanceAction;
use App\Actions\Finance\Customers\ManageCustomerOpeningBalanceAction;
use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Finance\Customers\SaveCustomerFinancialTermsAction;
use App\Contracts\PdfGenerator;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\DocumentVisibility;
use App\Services\Finance\CustomerCreditGuard;
use App\Services\Finance\CustomerFinancialPositionService;
use App\Services\Finance\CustomerStatementService;
use App\Services\Finance\Export\TabularExport;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\Sandbox\SandboxContext;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance clients - FC-02 "Comptes clients": financial customer index, account detail (six tabs), statements and the
 * customer-account mutations (opening balance, financial terms, settlements, advance allocation).
 *
 * Reads are authorized by finance.view AND the customer policy; every figure is viewer-scoped
 * (CustomerFinancialPositionService / DocumentVisibility). Each mutation re-checks its OWN dedicated permission in its action:
 * read-only Finance access never implies mutation authority. Nothing here is available in Test Mode.
 */
class CustomerAccountsController extends Controller
{
    private const FINANCIAL_SORTS = ['receivable', 'overdue', 'unbilled', 'net_invoiced', 'advances_available', 'available_credit'];

    private const EXPORT_LIMIT = 20000;

    private const FILTER_CAP = 5000;

    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, CustomerFinancialPositionService $positions): Response
    {
        [$organization, $filters] = $this->listScope($request, $context, $guard);
        $page = max(1, (int) $request->query('page', 1));
        $rows = $this->rows($organization, $request->user(), $filters, $positions, $page, 20);

        return Inertia::render('Finance/Clients/Accounts', [
            'customers' => (new LengthAwarePaginator($rows['items'], $rows['total'], 20, $page, ['path' => $request->url()]))->appends($request->query()),
            'filters' => $filters,
            'can' => ['export' => $request->user()->hasPermission($organization, 'finance.export')],
            'financialSortLimited' => $rows['limited'],
        ]);
    }

    public function export(Request $request, string $format, ActiveTenantContext $context, FinanceAccessGuard $guard, CustomerFinancialPositionService $positions, AuditLogger $audit): StreamedResponse|RedirectResponse
    {
        [$organization, $filters] = $this->listScope($request, $context, $guard);
        $guard->authorizeExport($request->user(), $organization);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $rows = $this->rows($organization, $request->user(), $filters, $positions, 1, self::EXPORT_LIMIT);
        if ($rows['total'] > self::EXPORT_LIMIT || $rows['limited']) {
            return back()->withErrors(['export' => 'Trop de comptes à exporter : affinez la recherche (maximum '.self::EXPORT_LIMIT.').']);
        }
        $audit->record('finance_clients.accounts_exported', $request->user(), $organization, null, null, newValues: ['format' => $format, 'filters' => $filters, 'row_count' => count($rows['items'])]);
        $headers = ['Client', 'Société', 'Type', 'Statut du client', 'Devise', 'Facturé net', 'Réglé (affecté)', 'Créance sur factures', 'Dont en retard (factures)', 'Exposition non facturée', 'Dont en retard (non facturé)',
            'Solde d’ouverture', 'Avances non affectées', 'Obligation de remboursement', 'Effets en attente', 'Plafond de crédit', 'Crédit disponible', 'Statut financier'];
        $lines = (function () use ($rows) {
            foreach ($rows['items'] as $r) {
                $p = $r['position'];
                yield [$r['display_name'], $r['company_name'], $r['type'], $r['status'], $p['currency_code'], $p['net_invoiced'], $p['paid'], $p['receivable'], $p['overdue_invoices'], $p['unbilled'], $p['overdue_unbilled'],
                    $p['opening_balance'], $p['advances_available'], $p['refund_obligation'], $p['pending_instruments'], $p['credit_limit'], $p['available_credit'], $p['financial_status']];
            }
        })();
        $money = range(5, 16);
        $name = 'comptes-clients-'.now()->format('Ymd-His');

        return $format === 'csv' ? TabularExport::csv($name, $headers, $lines, $money) : TabularExport::xlsx($name, 'Comptes clients', $headers, $lines, $money);
    }

    public function show(Request $request, Customer $customer, FinanceAccessGuard $guard, CustomerFinancialPositionService $positions, CustomerStatementService $statements, DocumentVisibility $visibility, SalesOrderPaymentCalculator $calculator): Response
    {
        $organization = $customer->organization;
        $user = $request->user();
        $guard->authorizeView($user, $organization);
        $this->authorize('view', $customer);
        $section = (string) $request->query('section', 'summary');
        $stores = $visibility->storeIds($user, $organization) ?: [0];
        $position = $positions->forCustomers($organization, [$customer->getKey()], $user)[$customer->getKey()];
        $props = [
            'customer' => $customer->only(['id', 'display_name', 'company_name', 'email', 'phone', 'status', 'type']),
            'position' => $position,
            'can' => [
                'commercialProfile' => $user->can('view', $customer),
                'openingBalance' => $user->hasPermission($organization, 'finance.customers.opening_balance.manage'),
                'terms' => $user->hasPermission($organization, 'finance.customers.terms.manage'),
                'settlements' => $user->hasPermission($organization, 'finance.customers.settlements.manage') && $user->hasPermission($organization, 'payments.create'),
                'creditOverride' => $user->hasPermission($organization, 'finance.customers.credit_override'),
                'export' => $user->hasPermission($organization, 'finance.export'),
            ],
        ];

        if ($section === 'invoices') {
            $invoices = $visibility->visible($user, $organization, Invoice::class)->where('customer_id', $customer->getKey())->where('status', '!=', InvoiceStatus::Superseded->value);
            $props += [
                'invoices' => (clone $invoices)->orderByDesc('invoice_date')->limit(50)->get(['id', 'invoice_number', 'version', 'invoice_date', 'due_date', 'status', 'subtotal_excl_tax', 'tax_total', 'total_incl_tax']),
                'invoicesCount' => (clone $invoices)->count(),
            ];
        }
        if ($section === 'payments') {
            $payments = $visibility->visible($user, $organization, Payment::class)
                ->where(fn ($q) => $q->where('customer_id', $customer->getKey())->orWhereHas('allocations.salesOrder', fn ($o) => $o->where('customer_id', $customer->getKey())->whereIn('store_id', $stores)));
            $rows = (clone $payments)->orderByDesc('payment_date')->orderByDesc('id')->limit(50)->get(['id', 'payment_number', 'payment_date', 'method', 'status', 'amount', 'customer_id', 'store_id']);
            $sums = DB::table('payment_allocations')->where('organization_id', $organization->getKey())->whereIn('payment_id', $rows->pluck('id'))->groupBy('payment_id')->select('payment_id')->selectRaw('SUM(amount) as total')->pluck('total', 'payment_id');
            $props += [
                'payments' => $rows->map(fn ($p) => $p->toArray() + ['allocated' => Decimal::normalize((string) ($sums[$p->id] ?? '0')), 'advance_left' => $p->customer_id && $p->status->value === 'posted'
                    ? Decimal::subtract((string) $p->amount, Decimal::normalize((string) ($sums[$p->id] ?? '0'))) : '0.0000']),
                'paymentsCount' => (clone $payments)->count(),
                'openOrders' => $this->openOrders($customer, $user, $calculator),
                'accounts' => DB::table('financial_accounts')->where('organization_id', $organization->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'currency_code', 'accepted_methods'])
                    ->map(fn ($a) => (array) $a + ['accepted_methods' => json_decode((string) $a->accepted_methods, true) ?: []]),
            ];
        }
        if ($section === 'schedule') {
            $props += ['openOrders' => $this->openOrders($customer, $user, $calculator)];
        }
        if ($section === 'statement') {
            [$from, $to] = $this->period($request);
            $props += ['statement' => $statements->build($organization, $customer, $user, $from, $to), 'period' => ['from' => $from, 'to' => $to]];
        }
        if ($section === 'terms') {
            $props += [
                'terms' => DB::table('customer_financial_terms')->where('organization_id', $organization->getKey())->where('customer_id', $customer->getKey())->first(),
                'openings' => DB::table('customer_opening_balances')->where('organization_id', $organization->getKey())->where('customer_id', $customer->getKey())->orderByDesc('effective_date')->orderByDesc('id')->get(),
                'exceptions' => DB::table('customer_credit_exceptions')->where('organization_id', $organization->getKey())->where('customer_id', $customer->getKey())->orderByDesc('id')->limit(20)->get(),
                'enforcement' => CustomerCreditGuard::enforcement($organization),
            ];
        }

        return Inertia::render('Finance/Clients/AccountShow', $props);
    }

    public function statementExport(Request $request, Customer $customer, string $format, FinanceAccessGuard $guard, CustomerStatementService $statements, PdfGenerator $pdf, AuditLogger $audit): StreamedResponse|HttpResponse
    {
        $organization = $customer->organization;
        $user = $request->user();
        $guard->authorizeExport($user, $organization);
        $this->authorize('view', $customer);
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        [$from, $to] = $this->period($request);
        $statement = $statements->build($organization, $customer, $user, $from, $to);
        abort_if($statement === null, 403);
        abort_if($statement['mixed_currency'], 422, 'Relevé indisponible : plusieurs devises.');
        $audit->record('customer_statement.exported', $user, $organization, null, $customer, newValues: ['format' => $format, 'from' => $from, 'to' => $to, 'rows' => count($statement['rows'])]);
        $name = 'releve-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $customer->display_name).'-'.$from.'_'.$to;
        if ($format === 'pdf') {
            $html = view('finance.customer-statement-pdf', ['organizationName' => $organization->name, 'customer' => $customer, 'statement' => $statement, 'generatedAt' => now()->format('d/m/Y H:i')])->render();

            return response($pdf->generate($html, ['pageNumbers' => true]), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"', 'Cache-Control' => 'private, no-store, max-age=0']);
        }
        $rows = (function () use ($statement) {
            yield ['', 'Solde d’ouverture', '', '', '', '', $statement['opening']];
            foreach ($statement['rows'] as $r) {
                yield [$r['date'], $r['type'], $r['reference'], $r['description'], $r['debit'], $r['credit'], $r['balance']];
            }
            yield ['', 'Solde de clôture', '', '', $statement['total_debit'], $statement['total_credit'], $statement['closing']];
        })();

        return TabularExport::xlsx($name, 'Relevé', ['Date', 'Type', 'Référence', 'Description', 'Débit', 'Crédit', 'Solde'], $rows, [4, 5, 6]);
    }

    // ------------------------------------------------------------------ mutations (each action re-checks its own permission)

    public function recordOpeningBalance(Request $request, Customer $customer, ManageCustomerOpeningBalanceAction $action): RedirectResponse
    {
        $this->authorize('view', $customer);
        $data = $request->validate(['effective_date' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'decimal:0,4'], 'currency_code' => ['required', 'string', 'size:3'],
            'reference' => ['required', 'string', 'max:120'], 'reason' => ['required', 'string', 'max:2000']]);
        $action->record($request->user(), $customer, $data);

        return back()->with('success', 'Solde d’ouverture enregistré.');
    }

    public function reverseOpeningBalance(Request $request, Customer $customer, int $openingBalance, ManageCustomerOpeningBalanceAction $action): RedirectResponse
    {
        $this->authorize('view', $customer);
        $action->reverse($request->user(), $customer, $openingBalance, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']);

        return back()->with('success', 'Solde d’ouverture inversé.');
    }

    public function saveTerms(Request $request, Customer $customer, SaveCustomerFinancialTermsAction $action): RedirectResponse
    {
        $this->authorize('view', $customer);
        $data = $request->validate(['credit_enabled' => ['boolean'], 'credit_limit' => ['nullable', 'decimal:0,4'], 'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'min_deposit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'overdue_tolerance_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'policy' => ['required', Rule::in(SaveCustomerFinancialTermsAction::POLICIES)], 'financial_status' => ['required', Rule::in(SaveCustomerFinancialTermsAction::STATUSES)]]);
        $action->execute($request->user(), $customer, $data);

        return back()->with('success', 'Conditions financières enregistrées.');
    }

    public function previewSettlement(Request $request, Customer $customer, RecordCustomerSettlementAction $action)
    {
        $this->authorize('view', $customer);
        $data = $request->validate(['amount' => ['required', 'decimal:0,4']]);

        return response()->json($action->previewOldestDueFirst($request->user(), $customer, $data['amount']));
    }

    public function settle(Request $request, Customer $customer, RecordCustomerSettlementAction $action): RedirectResponse
    {
        $this->authorize('view', $customer);
        $data = $request->validate([
            'client_operation_id' => ['required', 'uuid'], 'financial_account_id' => ['required', 'integer'], 'method' => ['required', 'string'],
            'amount' => ['required', 'decimal:0,4'], 'payment_date' => ['required', 'date_format:Y-m-d'], 'reference' => ['nullable', 'string', 'max:255'],
            'external_reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'],
            'allocations' => ['nullable', 'array', 'max:200'], 'allocations.*.sales_order_id' => ['required', 'integer'], 'allocations.*.amount' => ['required', 'decimal:0,4'],
        ]);
        $action->execute($request->user(), $customer, $data, $data['client_operation_id']);

        return back()->with('success', 'Règlement enregistré.');
    }

    public function allocateAdvance(Request $request, Customer $customer, Payment $payment, ManageCustomerAdvanceAction $action): RedirectResponse
    {
        $this->authorize('view', $customer);
        abort_unless($payment->customer_id === $customer->getKey(), 404);
        $data = $request->validate(['allocations' => ['required', 'array', 'min:1', 'max:200'], 'allocations.*.sales_order_id' => ['required', 'integer'], 'allocations.*.amount' => ['required', 'decimal:0,4']]);
        $action->allocate($request->user(), $payment, $data['allocations']);

        return back()->with('success', 'Avance affectée.');
    }

    public function deallocateAdvance(Request $request, Customer $customer, Payment $payment, SalesOrder $order, ManageCustomerAdvanceAction $action): RedirectResponse
    {
        $this->authorize('view', $customer);
        abort_unless($payment->customer_id === $customer->getKey() && $order->customer_id === $customer->getKey(), 404);
        $action->deallocate($request->user(), $payment, $order, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']);

        return back()->with('success', 'Affectation retirée.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: Organization, 1: array<string,mixed>} */
    private function listScope(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard): array
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $this->authorize('viewAny', [Customer::class, $organization]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'financial_status' => ['nullable', Rule::in(['normal', 'watch', 'overdue', 'over_limit', 'blocked'])],
            'sort' => ['nullable', Rule::in(array_merge(['name'], self::FINANCIAL_SORTS))], 'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        return [$organization, array_filter($filters, fn ($v) => $v !== null && $v !== '')];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{items: list<array<string,mixed>>, total: int, limited: bool}
     */
    private function rows(Organization $organization, User $user, array $filters, CustomerFinancialPositionService $positions, int $page, int $perPage): array
    {
        $query = Customer::query()->where('organization_id', $organization->getKey())
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($q) => $q->where('display_name', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, string $s) => $q->where('status', $s));
        $columns = ['id', 'display_name', 'company_name', 'type', 'email', 'phone', 'status'];
        $dir = ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $financial = in_array($filters['sort'] ?? 'name', self::FINANCIAL_SORTS, true) || isset($filters['financial_status']);

        if (! $financial) {
            $total = (clone $query)->count();
            $customers = $query->orderBy('display_name', $dir)->orderBy('id')->forPage($page, $perPage)->get($columns);
            $figures = $positions->forCustomers($organization, $customers->pluck('id')->all(), $user);

            return ['items' => $customers->map(fn ($c) => $this->row($c, $figures[$c->id]))->all(), 'total' => $total, 'limited' => false];
        }
        // Financial sort / filter needs every matching customer's figures: bounded (two grouped queries per chunk), capped.
        $all = $query->orderBy('display_name')->orderBy('id')->limit(self::FILTER_CAP + 1)->get($columns);
        if ($all->count() > self::FILTER_CAP) {
            return ['items' => [], 'total' => 0, 'limited' => true];
        }
        $figures = [];
        foreach ($all->pluck('id')->chunk(1000) as $chunk) {
            $figures += $positions->forCustomers($organization, $chunk->all(), $user);
        }
        $rows = $all->map(fn ($c) => $this->row($c, $figures[$c->id]))
            ->when($filters['financial_status'] ?? null, fn ($rows, string $status) => $rows->filter(fn ($r) => $r['position']['financial_status'] === $status));
        $sort = $filters['sort'] ?? 'name';
        if ($sort !== 'name') {
            $key = $sort === 'advances_available' ? 'advances_available' : $sort;
            $rows = $rows->sort(function ($a, $b) use ($key, $dir) {
                $x = $a['position'][$key];
                $y = $b['position'][$key];
                $cmp = $x === null ? ($y === null ? 0 : -1) : ($y === null ? 1 : Decimal::compare($x, $y));

                return $dir === 'desc' ? -$cmp : $cmp;
            });
        }
        $rows = $rows->values();

        return ['items' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->all(), 'total' => $rows->count(), 'limited' => false];
    }

    /** @param array<string,mixed> $position @return array<string,mixed> */
    private function row(Customer $customer, array $position): array
    {
        return ['id' => $customer->id, 'display_name' => $customer->display_name, 'company_name' => $customer->company_name, 'type' => $customer->type instanceof \BackedEnum ? $customer->type->value : $customer->type,
            'email' => $customer->email, 'phone' => $customer->phone, 'status' => $customer->status instanceof \BackedEnum ? $customer->status->value : $customer->status, 'position' => $position];
    }

    /** @return list<array<string,mixed>> the customer's open confirmed orders of the viewer's ACTIVE store (settlement targets) */
    private function openOrders(Customer $customer, User $user, SalesOrderPaymentCalculator $calculator): array
    {
        if ($user->active_store_id === null) {
            return [];
        }
        $out = [];
        foreach (SalesOrder::query()->where('organization_id', $customer->organization_id)->where('store_id', $user->active_store_id)->where('customer_id', $customer->getKey())
            ->where('status', 'confirmed')->whereNull('sandbox_id')->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('id')->limit(200)->get() as $order) {
            $remaining = $calculator->remainingAmount($order);
            if (Decimal::compare($remaining, '0') > 0) {
                $out[] = ['id' => $order->id, 'order_number' => $order->order_number, 'sale_date' => $order->sale_date->toDateString(), 'due_date' => $order->due_date?->toDateString(),
                    'total_incl_tax' => (string) $order->total_incl_tax, 'remaining' => $remaining, 'is_overdue' => $order->due_date !== null && $order->due_date->toDateString() < now()->toDateString()];
            }
        }

        return $out;
    }

    /** @return array{0:string,1:string} */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return [$data['from'] ?? now()->startOfMonth()->toDateString(), $data['to'] ?? now()->toDateString()];
    }
}
