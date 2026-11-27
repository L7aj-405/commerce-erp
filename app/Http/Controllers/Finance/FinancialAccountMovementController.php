<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Finance\Export\FinancialAccountMovementExport;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Finance\FinancialAccountMovementJournal;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only per-account movement journal (FIN-AUDIT-002).
 *
 * Authorization is the AND of the two existing permissions the Owner approved:
 * financial_accounts.view (FinancialAccountPolicy) AND finance.view (FinanceAccessGuard),
 * plus finance.export for the export. The journal reads PRODUCTION tables only, so every
 * Test Mode request (active or stale selection) is answered 404 — real account data never
 * appears in Test Mode (the sandbox middleware allowlist also keeps this route out of Test Mode).
 */
class FinancialAccountMovementController extends Controller
{
    public function index(Request $request, FinancialAccount $financialAccount, ActiveTenantContext $context, FinanceAccessGuard $guard, FinancialAccountMovementJournal $journal): Response
    {
        $organization = $this->authorizeJournal($request, $financialAccount, $context, $guard);
        $filters = $this->filters($request, $organization, $guard);

        $ctx = $journal->context($organization, $financialAccount, now());
        $reconciliation = $journal->reconcile($organization, $financialAccount, $ctx);
        $runningAvailable = $ctx['configured'] && $reconciliation['reconciled'];
        $rows = $journal->page($organization, $financialAccount, $ctx, $filters, $request->user(), max(1, (int) $request->query('page', 1)), 25, $runningAvailable, $request->url())
            ->appends($request->query());

        return Inertia::render('Finance/AccountMovements', [
            'account' => [
                'id' => $financialAccount->id, 'name' => $financialAccount->name, 'code' => $financialAccount->code,
                'type' => $financialAccount->type->value, 'status' => $financialAccount->status->value, 'currency_code' => $financialAccount->currency_code,
            ],
            'position' => [
                'as_of' => $ctx['asOf'], 'configured' => $ctx['configured'], 'effective_date' => $ctx['effective'],
                'opening_amount' => $ctx['opening_amount'], 'balance' => $ctx['balance'],
            ],
            'running' => [
                'available' => $runningAvailable,
                'reason' => ! $ctx['configured'] ? 'not_configured' : ($reconciliation['reconciled'] ? null : 'not_reconciled'),
                'has_reversals' => $ctx['reversalDates']['payments'] !== [] || $ctx['reversalDates']['finance_outgoing_payments'] !== [],
            ],
            'filters' => $filters + ['date_from' => null, 'date_to' => null, 'type' => null, 'reference' => null, 'store_id' => null],
            'types' => FinancialAccountMovementJournal::TYPES,
            'stores' => Store::query()->where('organization_id', $organization->getKey())->orderBy('name')->get(['id', 'name', 'code']),
            'rows' => $rows,
            'can' => ['export' => $request->user()->hasPermission($organization, 'finance.export')],
            'exportMaxRows' => FinancialAccountMovementJournal::EXPORT_MAX_ROWS,
        ]);
    }

    public function export(Request $request, FinancialAccount $financialAccount, string $format, ActiveTenantContext $context, FinanceAccessGuard $guard, FinancialAccountMovementJournal $journal, FinancialAccountMovementExport $export, AuditLogger $audit): StreamedResponse|RedirectResponse
    {
        $organization = $this->authorizeJournal($request, $financialAccount, $context, $guard);
        $guard->authorizeExport($request->user(), $organization);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $filters = $this->filters($request, $organization, $guard);

        $ctx = $journal->context($organization, $financialAccount, now());
        $count = $journal->count($organization, $financialAccount, $ctx, $filters);
        if ($count > FinancialAccountMovementJournal::EXPORT_MAX_ROWS) {
            return back()->withErrors(['export' => 'Trop de lignes à exporter ('.$count.'). Réduisez la période ou ajoutez un filtre (maximum '.FinancialAccountMovementJournal::EXPORT_MAX_ROWS.').']);
        }
        $reconciled = $ctx['configured'] && $journal->reconcile($organization, $financialAccount, $ctx)['reconciled'];

        // Audit scope only — filters, format and row count; never the exported data.
        $audit->record('financial_account.movements_exported', $request->user(), $organization, null, $financialAccount, newValues: [
            'format' => $format, 'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''), 'row_count' => $count, 'running_balance' => $reconciled,
        ]);

        $name = 'mouvements-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $financialAccount->code).'-'.now()->format('Ymd-His');
        if ($format === 'csv') {
            return response()->streamDownload($export->csv($organization, $financialAccount, $ctx, $filters, $request->user(), $reconciled), $name.'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store, max-age=0',
            ]);
        }
        $path = $export->xlsx($organization, $financialAccount, $ctx, $filters, $request->user(), $reconciled);

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, $name.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function authorizeJournal(Request $request, FinancialAccount $account, ActiveTenantContext $context, FinanceAccessGuard $guard): Organization
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        abort_unless($account->organization_id === $organization->getKey(), 404);
        $this->authorize('view', $account);
        $guard->authorizeView($request->user(), $organization);

        return $organization;
    }

    /** @return array<string,mixed> */
    private function filters(Request $request, Organization $organization, FinanceAccessGuard $guard): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['nullable', Rule::in(FinancialAccountMovementJournal::TYPES)],
            'reference' => ['nullable', 'string', 'max:100'], 'store_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['store_id'])) {
            $guard->resolveStore($organization, $data['store_id']);
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }
}
