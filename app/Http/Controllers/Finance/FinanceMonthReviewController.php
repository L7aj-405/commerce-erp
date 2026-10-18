<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\Export\FinanceMonthlySituationExcelExport;
use App\Services\Security\FreshAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceMonthReviewController extends Controller
{
    public function export(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceMonthlySituationExcelExport $export): StreamedResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituationExport($request->user(), $organization);
        $filters = $request->validate([
            'month' => ['required', 'date_format:Y-m'], 'store_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:50'], 'supplier_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:30'], 'search' => ['nullable', 'string', 'max:255'],
        ]);
        $period = FinancePeriod::fromMonth($filters['month']);
        $store = $guard->resolveStore($organization, $filters['store_id'] ?? null);
        $bytes = $export->build($organization, $period, $store, $filters);

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'situation-fin-de-mois-'.$period->month.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function review(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, ManageFinanceMonthReviewAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituationManage($request->user(), $organization);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'note' => ['nullable', 'string', 'max:2000']]);
        $action->review($request->user(), $organization, FinancePeriod::fromMonth($data['month']), $data['note'] ?? null);
        return back()->with('success', 'Mois marqué comme vérifié.');
    }

    public function close(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FreshAuthentication $fresh, ManageFinanceMonthReviewAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituationManage($request->user(), $organization);
        $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'], 'note' => ['nullable', 'string', 'max:2000'],
            'acknowledge_warnings' => ['nullable', 'boolean'],
        ]);
        $action->close($request->user(), $organization, FinancePeriod::fromMonth($data['month']), $data['note'] ?? null, (bool) ($data['acknowledge_warnings'] ?? false));
        return back()->with('success', 'Situation mensuelle clôturée pour le suivi interne.');
    }

    public function reopen(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FreshAuthentication $fresh, ManageFinanceMonthReviewAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituationManage($request->user(), $organization);
        $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'reason' => ['required', 'string', 'max:2000']]);
        $action->reopen($request->user(), $organization, FinancePeriod::fromMonth($data['month']), $data['reason']);
        return back()->with('success', 'Mois réouvert.');
    }
}
