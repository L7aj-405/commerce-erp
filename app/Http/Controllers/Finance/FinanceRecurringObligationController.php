<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\GenerateRecurringFinanceObligationsAction;
use App\Actions\Finance\ManageFinanceRecurringTemplateAction;
use App\Enums\FinanceObligationCategory;
use App\Enums\FinanceRecurringDueDayMode;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\FinanceObligation;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\FinancialAccount;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use App\Services\Finance\FinanceAccessGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FinanceRecurringObligationController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, GenerateRecurringFinanceObligationsAction $generator): Response
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituation($request->user(), $organization);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'category' => ['nullable', Rule::enum(FinanceObligationCategory::class)],
            'store_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $store = $guard->resolveStore($organization, $filters['store_id'] ?? null);
        $templates = FinanceRecurringObligationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->where('active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $query) => $query->where('active', false))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, mixed $supplier) => $query->where('supplier_id', $supplier))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
                $query->where(fn (Builder $nested) => $nested->where('template_number', 'like', $like)
                    ->orWhere('label', 'like', $like)->orWhere('reference', 'like', $like)
                    ->orWhere('beneficiary_name', 'like', $like)
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like)));
            })
            ->with([
                'store:id,name,code', 'supplier:id,name', 'preferredFinancialAccount:id,name,code', 'createdBy:id,name',
                'obligations' => fn ($query) => $query->latest('recurring_period')->latest('id')->limit(5),
            ])
            ->withCount('obligations')->withMax('obligations', 'recurring_period')
            ->orderByDesc('active')->orderBy('label')->orderBy('id')
            ->paginate(20)->withQueryString();

        $templates->through(function (FinanceRecurringObligationTemplate $template) use ($generator) {
            $next = $this->nextDueDate($template, $generator);
            return [
                'id' => $template->id, 'number' => $template->template_number,
                'category' => $template->category->value, 'category_label' => $template->category->label(),
                'label' => $template->label, 'store' => $template->store?->only(['id', 'name', 'code']),
                'supplier' => $template->supplier?->only(['id', 'name']), 'supplier_id' => $template->supplier_id,
                'beneficiary_name' => $template->beneficiary_name,
                'beneficiary' => $template->supplier?->name ?: $template->beneficiary_name ?: '—',
                'reference' => $template->reference, 'planned_amount' => (string) $template->planned_amount,
                'currency_code' => $template->currency_code, 'planned_payment_method' => $template->planned_payment_method?->value,
                'preferred_financial_account_id' => $template->preferred_financial_account_id,
                'due_day_mode' => $template->due_day_mode->value, 'due_day' => $template->due_day,
                'due_label' => $template->due_day_mode === FinanceRecurringDueDayMode::EndOfMonth ? 'Fin du mois' : 'Le '.$template->due_day,
                'start_date' => $template->start_date->toDateString(), 'end_date' => $template->end_date?->toDateString(),
                'active' => $template->active, 'notes' => $template->notes,
                'created_by' => $template->createdBy?->name, 'created_at' => $template->created_at?->toIso8601String(),
                'occurrences_count' => $template->obligations_count, 'last_period' => $template->obligations_max_recurring_period,
                'next_due_date' => $next?->toDateString(),
                'occurrences' => $template->obligations->map(fn (FinanceObligation $row) => [
                    'id' => $row->id, 'number' => $row->obligation_number, 'period' => $row->recurring_period?->format('Y-m'),
                    'due_date' => $row->due_date->toDateString(), 'amount' => (string) $row->planned_amount,
                ])->values()->all(),
            ];
        });

        return Inertia::render('Finance/RecurringObligations', [
            'organization' => $organization->only(['id', 'name']), 'filters' => $filters,
            'templates' => $templates,
            'stores' => Store::query()->where('organization_id', $organization->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'suppliers' => Supplier::query()->where('organization_id', $organization->id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'financialAccounts' => FinancialAccount::query()->where('organization_id', $organization->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'currency_code']),
            'categories' => collect(FinanceObligationCategory::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()]),
            'paymentMethods' => collect(PaymentMethod::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->operationalLabel()]),
            'currencyCode' => config('platform.currency_code', 'MAD'),
            'canManage' => $request->user()->hasPermission($organization, 'finance.monthly_situation.manage'),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, ManageFinanceRecurringTemplateAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituationManage($request->user(), $organization);
        $action->create($request->user(), $organization, $request->validate($this->rules()));
        return back()->with('success', 'Charge récurrente ajoutée.');
    }

    public function update(Request $request, FinanceRecurringObligationTemplate $recurringTemplate, FinanceAccessGuard $guard, ManageFinanceRecurringTemplateAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $recurringTemplate->organization);
        $action->update($request->user(), $recurringTemplate, $request->validate($this->rules()));
        return back()->with('success', 'Charge récurrente mise à jour. Les occurrences déjà générées restent inchangées.');
    }

    public function deactivate(Request $request, FinanceRecurringObligationTemplate $recurringTemplate, FinanceAccessGuard $guard, ManageFinanceRecurringTemplateAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $recurringTemplate->organization);
        $action->deactivate($request->user(), $recurringTemplate);
        return back()->with('success', 'Charge récurrente désactivée.');
    }

    public function reactivate(Request $request, FinanceRecurringObligationTemplate $recurringTemplate, FinanceAccessGuard $guard, ManageFinanceRecurringTemplateAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $recurringTemplate->organization);
        $action->reactivate($request->user(), $recurringTemplate);
        return back()->with('success', 'Charge récurrente réactivée.');
    }

    public function generate(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, GenerateRecurringFinanceObligationsAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituationManage($request->user(), $organization);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $result = $action->execute($organization, $data['month'], $request->user());
        return back()->with('success', "Charges récurrentes : {$result['created']} créée(s), {$result['existing']} déjà présente(s), {$result['skipped']} ignorée(s).");
    }

    private function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer'], 'category' => ['required', Rule::enum(FinanceObligationCategory::class)],
            'label' => ['required', 'string', 'max:255'], 'supplier_id' => ['nullable', 'integer'],
            'beneficiary_name' => ['nullable', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:255'],
            'planned_amount' => ['required', 'decimal:0,4', 'gt:0'], 'currency_code' => ['required', 'string', 'size:3'],
            'planned_payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'preferred_financial_account_id' => ['nullable', 'integer'],
            'due_day_mode' => ['required', Rule::enum(FinanceRecurringDueDayMode::class)],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function nextDueDate(FinanceRecurringObligationTemplate $template, GenerateRecurringFinanceObligationsAction $generator): ?Carbon
    {
        if (! $template->active) return null;
        $period = now()->startOfMonth()->max($template->start_date->copy()->startOfMonth());
        for ($attempt = 0; $attempt < 2; $attempt++, $period->addMonth()) {
            $due = $generator->dueDate($template, $period);
            if ($due->gte($template->start_date) && $due->gte(now()->startOfDay())
                && (! $template->end_date || $period->lte($template->end_date->copy()->startOfMonth()))) return $due;
        }
        return null;
    }
}
