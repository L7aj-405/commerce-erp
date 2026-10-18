<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\ManageFinancePaymentInstrumentAction;
use App\Enums\FinancePaymentInstrumentType;
use App\Http\Controllers\Controller;
use App\Models\FinanceObligation;
use App\Models\FinancePaymentInstrument;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Security\FreshAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancePaymentInstrumentController extends Controller
{
    public function store(Request $request, FinanceObligation $obligation, FinanceAccessGuard $guard, ManageFinancePaymentInstrumentAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $obligation->organization);
        $action->create($request->user(), $obligation, $request->validate([
            'type' => ['required', Rule::enum(FinancePaymentInstrumentType::class)], 'external_reference' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'decimal:0,4', 'gt:0'], 'issue_date' => ['required', 'date'], 'maturity_date' => ['required', 'date'],
            'financial_account_id' => ['required', 'integer'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]));
        return back()->with('success', 'Instrument de règlement programmé. Aucun décaissement n’a encore été comptabilisé.');
    }
    public function present(Request $request, FinancePaymentInstrument $instrument, FinanceAccessGuard $guard, ManageFinancePaymentInstrumentAction $action): RedirectResponse
    { $guard->authorizeMonthlySituationManage($request->user(), $instrument->organization); $action->present($request->user(), $instrument); return back()->with('success', 'Instrument marqué comme présenté.'); }
    public function cancel(Request $request, FinancePaymentInstrument $instrument, FinanceAccessGuard $guard, FreshAuthentication $fresh, ManageFinancePaymentInstrumentAction $action): RedirectResponse
    { $guard->authorizeMonthlySituationManage($request->user(), $instrument->organization); $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT); $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]); $action->cancel($request->user(), $instrument, $data['reason']); return back()->with('success', 'Instrument annulé.'); }
    public function clear(Request $request, FinancePaymentInstrument $instrument, FinanceAccessGuard $guard, FreshAuthentication $fresh, ManageFinancePaymentInstrumentAction $action): RedirectResponse
    { $guard->authorizeOutgoingPaymentManage($request->user(), $instrument->organization); $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT); $action->clear($request->user(), $instrument); return back()->with('success', 'Instrument compensé et décaissement comptabilisé.'); }
}
