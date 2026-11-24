<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Finance\Export\TabularExport;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Finance\FinanceJournalService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceJournalController extends Controller
{
    private const EVENT_TYPES = ['invoice', 'credit_note', 'payment', 'refund', 'payment_reversal', 'opening_balance', 'opening_balance_reversal', 'allocation', 'deallocation',
        'instrument_received', 'instrument_in_portfolio', 'instrument_submitted', 'instrument_cleared', 'instrument_rejected', 'instrument_cancelled'];

    public function index(
        Request $request,
        ActiveTenantContext $context,
        FinanceAccessGuard $guard,
        FinanceJournalService $journal,
    ): Response {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);

        $month = $request->query('month');
        $period = $month ? FinancePeriod::fromMonth($month) : FinancePeriod::current($organization);
        $store = $guard->resolveStore($organization, $request->query('store_id'));
        $filters = $this->filters($request);

        return Inertia::render('Finance/Journal', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'storeId' => $store?->id,
            'stores' => Store::query()->where('organization_id', $organization->getKey())
                ->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'rows' => $journal->rows($organization, $period, $store),
            'events' => $journal->events($organization, $period, $store, $filters)->values(),
            'filters' => $filters,
            'eventTypes' => self::EVENT_TYPES,
            'can' => ['export' => $request->user()->hasPermission($organization, 'finance.export')],
        ]);
    }

    /** Customer journal events (read-only), same service, period, store and filters as the page; finance.export; audited. */
    public function export(Request $request, string $format, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceJournalService $journal, AuditLogger $audit): StreamedResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $guard->authorizeExport($request->user(), $organization);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $month = $request->query('month');
        $period = $month ? FinancePeriod::fromMonth($month) : FinancePeriod::current($organization);
        $store = $guard->resolveStore($organization, $request->query('store_id'));
        $filters = $this->filters($request);
        $events = $journal->events($organization, $period, $store, $filters)->values();
        $audit->record('finance_clients.journal_exported', $request->user(), $organization, $store, null, newValues: ['format' => $format, 'month' => $period->month, 'filters' => $filters, 'row_count' => $events->count()]);
        $headers = ['Date', 'Type', 'Numéro', 'Référence', 'Client', 'Montant', 'Sens', 'Informatif'];
        $lines = $events->map(fn (array $e) => [$e['date'], $e['label'], $e['number'], $e['reference'], $e['customer'], $e['amount'], $e['negative'] ? '-' : '+', ($e['informational'] ?? false) ? 'oui' : 'non']);
        $name = 'journal-clients-'.$period->month;

        return $format === 'csv' ? TabularExport::csv($name, $headers, $lines) : TabularExport::xlsx($name, 'Journal clients', $headers, $lines, [5]);
    }

    /** @return array{type?:string, search?:string} */
    private function filters(Request $request): array
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(self::EVENT_TYPES)], 'search' => ['nullable', 'string', 'max:100']]);

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }
}
