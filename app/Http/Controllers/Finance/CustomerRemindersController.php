<?php

namespace App\Http\Controllers\Finance;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Finance\CustomerFinancialPositionService;
use App\Services\Finance\CustomerReminderTemplates;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Sandbox\SandboxContext;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manual customer payment reminders (FC-05 "Relances"): preview a rendered French/Arabic message for a customer, record it as a
 * follow-up in the EXISTING follow-up module, and let finance managers customise the wording. Nothing is sent externally.
 */
class CustomerRemindersController extends Controller
{
    public function preview(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, CustomerReminderTemplates $templates, CustomerFinancialPositionService $positions): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeReceivables($request->user(), $organization);
        $data = $request->validate(['customer_id' => ['required', 'integer'], 'template' => ['required', Rule::in(CustomerReminderTemplates::KEYS)], 'language' => ['required', Rule::in(['fr', 'ar'])]]);
        $customer = Customer::query()->where('organization_id', $organization->getKey())->findOrFail($data['customer_id']);
        $this->authorize('view', $customer);

        $vars = $this->variables($organization, $customer, $request, $positions);
        // Operational reminders always use the organization's CURRENT business date (never the historical reporting month).
        if (! $this->hasOverdue($vars)) {
            return response()->json(['message' => 'Aucun montant échu à la date du '.$vars['business_date'].' : aucune relance de retard à générer.', 'business_date' => $vars['business_date']], 422);
        }

        return response()->json(['message' => $templates->render($organization, $data['template'], $data['language'], $vars), 'business_date' => $vars['business_date'], 'amount' => $vars['amount'], 'currency' => $vars['currency']]);
    }

    public function followUp(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, CustomerReminderTemplates $templates, CustomerFinancialPositionService $positions, SaveFollowUpAction $followUps, AuditLogger $audit): RedirectResponse
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        $guard->authorizeReceivables($request->user(), $organization);
        $data = $request->validate(['customer_id' => ['required', 'integer'], 'template' => ['required', Rule::in(CustomerReminderTemplates::KEYS)], 'language' => ['required', Rule::in(['fr', 'ar'])],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:60']]);
        $customer = Customer::query()->where('organization_id', $organization->getKey())->findOrFail($data['customer_id']);
        $this->authorize('view', $customer);
        $vars = $this->variables($organization, $customer, $request, $positions);
        if (! $this->hasOverdue($vars)) {
            throw ValidationException::withMessages(['customer_id' => 'Aucun montant échu à la date du '.$vars['business_date'].' : aucune relance de retard à enregistrer.']);
        }
        $message = $templates->render($organization, $data['template'], $data['language'], $vars);
        $due = Carbon::parse(BusinessTime::today($organization))->addDays((int) ($data['due_in_days'] ?? 0))->toDateString();
        $followUp = $followUps->create($request->user(), $organization, ['title' => 'Relance paiement — '.$customer->display_name, 'note' => $message, 'customer_id' => $customer->getKey(), 'due_date' => $due]);
        $audit->record('customer_reminder.follow_up_created', $request->user(), $organization, null, $customer, newValues: ['follow_up_id' => $followUp->getKey(), 'template' => $data['template'], 'language' => $data['language']]);

        return back()->with('success', 'Relance enregistrée dans les suivis (aucun envoi automatique).');
    }

    public function saveTemplate(Request $request, ActiveTenantContext $context, CustomerReminderTemplates $templates, AuditLogger $audit): RedirectResponse
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'finance.customers.schedules.manage'), 403);
        $data = $request->validate(['template' => ['required', Rule::in(CustomerReminderTemplates::KEYS)], 'fr' => ['required', 'string', 'max:2000'], 'ar' => ['required', 'string', 'max:2000']]);
        $templates->save($organization, $data['template'], $data['fr'], $data['ar']);
        $audit->record('customer_reminder.template_saved', $request->user(), $organization, null, $organization, newValues: ['template' => $data['template']]);

        return back()->with('success', 'Modèle de relance enregistré.');
    }

    /** @return array<string, scalar|null> */
    private function variables($organization, Customer $customer, Request $request, CustomerFinancialPositionService $positions): array
    {
        $businessDate = BusinessTime::today($organization);
        $today = Carbon::parse($businessDate);
        // The overdue basis is the business date, exactly like the eligible-customers list (same service, same date).
        $p = $positions->forCustomers($organization, [$customer->getKey()], $request->user(), null, $today)[$customer->getKey()];
        $firstDue = DB::table('sales_orders')->where('organization_id', $organization->getKey())->where('customer_id', $customer->getKey())
            ->where('status', 'confirmed')->whereNotNull('due_date')->where('due_date', '<', $businessDate)->min('due_date');
        $days = $firstDue ? (int) Carbon::parse($firstDue)->diffInDays($today, true) : null;

        // No fallback to the total receivable: a customer with nothing overdue has no overdue amount (null), never a different figure.
        return ['customer' => $customer->display_name, 'organization' => $organization->name, 'amount' => $p['overdue'] ?? null, 'currency' => $p['currency_code'],
            'due_date' => $firstDue ? substr((string) $firstDue, 0, 10) : null, 'oldest_days' => $days, 'business_date' => $businessDate];
    }

    /** @param array<string, scalar|null> $vars */
    private function hasOverdue(array $vars): bool
    {
        return $vars['amount'] !== null && Decimal::compare((string) $vars['amount'], '0') > 0;
    }
}
