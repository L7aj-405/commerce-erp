<?php

namespace App\Http\Controllers\FollowUps;

use App\Actions\FollowUps\SaveFollowUpRecurringRuleAction;
use App\Actions\FollowUps\TransitionFollowUpRecurringRuleAction;
use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Models\FollowUpRecurringRule;
use App\Services\ActiveTenantContext;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\FollowUps\FollowUpRecurringQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Work Center "Recurring": transport only — rules live in the recurring actions/services. */
class FollowUpRecurringController extends Controller
{
    private const INPUT = ['title', 'note', 'priority', 'assigned_user_id', 'customer_id', 'quotation_id', 'frequency', 'interval_count',
        'weekdays', 'month_day', 'local_time', 'starts_on', 'ends_on', 'max_occurrences', 'expected_version'];

    public function index(Request $request, ActiveTenantContext $context, FollowUpRecurringQuery $rules, FollowUpQuery $followUps): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [FollowUp::class, $organization]);
        $filters = array_filter($request->validate(FollowUpRecurringQuery::rules()), fn ($value) => $value !== null && $value !== '');
        $filters['assigned_to'] ??= 'me';
        $user = $request->user();
        $page = $rules->search($user, $organization, $filters);

        return Inertia::render('Work/Recurring', [
            'filters' => $filters,
            'rules' => [
                'data' => collect($page->items())->map(fn (FollowUpRecurringRule $rule) => $rules->present($user, $organization, $rule))->all(),
                'links' => $page->linkCollection()->toArray(),
                'total' => $page->total(),
            ],
            'context' => $followUps->formContext($user, $organization),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, SaveFollowUpRecurringRuleAction $action): RedirectResponse
    {
        $action->create($request->user(), $context->organizationOrFail(), $request->only(self::INPUT));

        return back()->with('success', 'Récurrence créée.');
    }

    public function update(Request $request, ActiveTenantContext $context, FollowUpRecurringRule $recurringRule, SaveFollowUpRecurringRuleAction $action): RedirectResponse
    {
        $action->update($request->user(), $context->organizationOrFail(), $recurringRule, $request->only(self::INPUT));

        return back()->with('success', 'Récurrence mise à jour.');
    }

    public function transition(Request $request, ActiveTenantContext $context, FollowUpRecurringRule $recurringRule, TransitionFollowUpRecurringRuleAction $action): RedirectResponse
    {
        $action->execute($request->user(), $context->organizationOrFail(), $recurringRule, $request->only(['transition', 'expected_version']));

        return back()->with('success', 'Récurrence mise à jour.');
    }
}
