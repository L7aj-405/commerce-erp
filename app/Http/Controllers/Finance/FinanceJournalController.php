<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Finance\FinanceJournalService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceJournalController extends Controller
{
    public function index(
        Request $request,
        ActiveTenantContext $context,
        FinanceAccessGuard $guard,
        FinanceJournalService $journal,
    ): Response {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);

        $month = $request->query('month');
        $period = $month ? FinancePeriod::fromMonth($month) : FinancePeriod::current();
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        return Inertia::render('Finance/Journal', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'storeId' => $store?->id,
            'stores' => Store::query()->where('organization_id', $organization->getKey())
                ->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'rows' => $journal->rows($organization, $period, $store),
            'can' => ['export' => $request->user()->hasPermission($organization, 'finance.export')],
        ]);
    }
}
