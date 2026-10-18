<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\ManageFinancialAccountAction;
use App\Actions\Finance\SetFinancialAccountOpeningPositionAction;
use App\Enums\FinancialAccountStatus;
use App\Enums\FinancialAccountType;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Services\ActiveTenantContext;
use App\Services\Finance\FinancialAccountBalanceService;
use App\Services\Security\FreshAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FinancialAccountController extends Controller
{
    public function index(ActiveTenantContext $context, FinancialAccountBalanceService $balances): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [FinancialAccount::class, $organization]);

        return Inertia::render('Payments/FinancialAccounts/Index', [
            'accounts' => FinancialAccount::query()
                ->where('organization_id', $organization->getKey())
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'type', 'status', 'currency_code', 'notes', 'accepted_methods']),
            'positions' => $balances->organizationPosition($organization, now()),
            'currencyCode' => config('platform.currency_code', 'MAD'),
            'paymentMethods' => array_map(fn (PaymentMethod $method) => [
                'value' => $method->value,
                'label' => $method->operationalLabel(),
            ], PaymentMethod::cases()),
            'can' => [
                'create' => request()->user()->can('create', [FinancialAccount::class, $organization]),
                'update' => request()->user()->hasPermission($organization, 'financial_accounts.update'),
                'manageBalance' => request()->user()->hasPermission($organization, 'finance.financial_accounts.manage_balance'),
            ],
        ]);
    }

    public function openingPosition(Request $request, FinancialAccount $financialAccount, FreshAuthentication $fresh, SetFinancialAccountOpeningPositionAction $action): RedirectResponse
    {
        abort_unless($request->user()->hasPermission($financialAccount->organization_id, 'finance.financial_accounts.manage_balance'), 403);
        $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT);
        $data = $request->validate(['effective_date' => ['required','date'], 'amount' => ['required','decimal:0,4'],
            'currency_code' => ['required','string','size:3','uppercase'], 'reference' => ['nullable','string','max:255'], 'notes' => ['nullable','string','max:5000']]);
        $action->execute($request->user(), $financialAccount, $data);
        return back()->with('success', 'Solde d’ouverture enregistré.');
    }

    public function store(Request $request, ActiveTenantContext $context, ManageFinancialAccountAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [FinancialAccount::class, $organization]);
        $action->create($request->user(), $organization, $request->validate($this->rules($organization->getKey())));

        return back();
    }

    public function update(Request $request, FinancialAccount $financialAccount, ManageFinancialAccountAction $action): RedirectResponse
    {
        $this->authorize('update', $financialAccount);
        $action->update($request->user(), $financialAccount, $request->validate($this->rules(
            $financialAccount->organization_id,
            $financialAccount->getKey(),
        )));

        return back();
    }

    /** @return array<string, mixed> */
    private function rules(int $organizationId, ?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', Rule::unique('financial_accounts', 'code')->where(fn ($query) => $query->where('organization_id', $organizationId))->ignore($ignoreId)],
            'type' => ['required', Rule::enum(FinancialAccountType::class)],
            'status' => ['required', Rule::enum(FinancialAccountStatus::class)],
            'currency_code' => ['required', 'string', 'size:3', 'uppercase'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // A Finance Account must accept at least one real Payment method
            // — never a fabricated one. This is the authoritative list
            // RecordPaymentAction checks against (FinancialAccount::acceptsMethod()).
            'accepted_methods' => ['required', 'array', 'min:1'],
            'accepted_methods.*' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }
}
