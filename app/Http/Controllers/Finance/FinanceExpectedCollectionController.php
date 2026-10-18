<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\ManageFinanceExpectedCollectionAction;
use App\Http\Controllers\Controller;
use App\Models\FinanceExpectedCollection;
use App\Services\ActiveTenantContext;
use App\Services\Finance\FinanceAccessGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FinanceExpectedCollectionController extends Controller
{
    public function store(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, ManageFinanceExpectedCollectionAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail(); $guard->authorizeMonthlySituationManage($request->user(), $organization);
        $action->create($request->user(), $organization, $request->validate($this->rules()));
        return back()->with('success', 'Encaissement attendu planifié.');
    }

    public function update(Request $request, FinanceExpectedCollection $expectedCollection, FinanceAccessGuard $guard, ManageFinanceExpectedCollectionAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $expectedCollection->organization);
        $action->update($request->user(), $expectedCollection, $request->validate($this->rules()));
        return back()->with('success', 'Encaissement attendu mis à jour.');
    }

    public function cancel(Request $request, FinanceExpectedCollection $expectedCollection, FinanceAccessGuard $guard, ManageFinanceExpectedCollectionAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $expectedCollection->organization);
        $data = $request->validate(['reason' => ['required','string','max:2000']]);
        $action->cancel($request->user(), $expectedCollection, $data['reason']);
        return back()->with('success', 'Encaissement attendu annulé.');
    }

    public function match(Request $request, FinanceExpectedCollection $expectedCollection, FinanceAccessGuard $guard, ManageFinanceExpectedCollectionAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $expectedCollection->organization);
        $data = $request->validate(['payment_allocation_id' => ['required','integer'], 'amount' => ['required','decimal:0,4','gt:0']]);
        $action->match($request->user(), $expectedCollection, (int) $data['payment_allocation_id'], $data['amount']);
        return back()->with('success', 'Encaissement rapproché avec le paiement.');
    }

    private function rules(): array
    {
        return ['sales_order_id' => ['required','integer'], 'label' => ['nullable','string','max:255'], 'reference' => ['nullable','string','max:255'],
            'expected_date' => ['required','date'], 'expected_amount' => ['required','decimal:0,4','gt:0'], 'notes' => ['nullable','string','max:5000']];
    }
}
