<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\ManageFinancialAccountAction;
use App\Enums\FinancialAccountStatus;
use App\Enums\FinancialAccountType;
use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FinancialAccountController extends Controller
{
    public function index(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [FinancialAccount::class, $organization]);

        return Inertia::render('Payments/FinancialAccounts/Index', [
            'accounts' => FinancialAccount::query()
                ->where('organization_id', $organization->getKey())
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'type', 'status', 'currency_code', 'notes']),
            'currencyCode' => config('platform.currency_code', 'MAD'),
            'can' => [
                'create' => request()->user()->can('create', [FinancialAccount::class, $organization]),
                'update' => request()->user()->hasPermission($organization, 'financial_accounts.update'),
            ],
        ]);
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
        ];
    }
}
