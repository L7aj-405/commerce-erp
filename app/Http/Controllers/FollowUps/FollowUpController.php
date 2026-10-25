<?php

namespace App\Http\Controllers\FollowUps;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Enums\FollowUpStatus;
use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Models\Quotation;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\FollowUps\FollowUpQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "My Work": transport only — validation and business rules live in the follow-up actions/services. */
class FollowUpController extends Controller
{
    private const TABS = ['today', 'overdue', 'upcoming', 'completed', 'all'];

    private const INPUT = ['title', 'note', 'priority', 'assigned_user_id', 'customer_id', 'quotation_id', 'due_date', 'due_time', 'due_at', 'expected_version'];

    public function index(Request $request, ActiveTenantContext $context, FollowUpQuery $followUps): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [FollowUp::class, $organization]);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'today';
        $filters = $request->validate(FollowUpQuery::rules());
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $filters['assigned_to'] ??= 'me';
        unset($filters['due'], $filters['status']);
        if (in_array($tab, FollowUpQuery::DUE_BUCKETS, true)) $filters['due'] = $tab;
        if ($tab === 'completed') $filters['status'] = FollowUpStatus::Completed->value;

        $user = $request->user();
        $page = $followUps->search($user, $organization, $filters);

        return Inertia::render('Work/Index', [
            'tab' => $tab,
            'filters' => array_diff_key($filters, ['due' => true, 'status' => true]),
            'followUps' => [
                'data' => collect($page->items())->map(fn (FollowUp $followUp) => $followUps->present($user, $organization, $followUp))->all(),
                'links' => $page->linkCollection()->toArray(),
                'total' => $page->total(),
            ],
            'counts' => $followUps->counts($user, $organization, (string) $filters['assigned_to']),
            'context' => $followUps->formContext($user, $organization),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, SaveFollowUpAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [FollowUp::class, $organization]);
        $action->create($request->user(), $organization, $request->only(self::INPUT));

        return back()->with('success', 'Suivi créé.');
    }

    public function update(Request $request, ActiveTenantContext $context, FollowUp $followUp, SaveFollowUpAction $action): RedirectResponse
    {
        $this->authorize('update', $followUp);
        $action->update($request->user(), $context->organizationOrFail(), $followUp, $request->only(self::INPUT));

        return back()->with('success', 'Suivi mis à jour.');
    }

    /**
     * Open the follow-up's quotation even when it belongs to another of the
     * viewer's stores: the active store is switched through the canonical
     * ActiveTenantContext::activateStore (same checks as the store switcher)
     * before redirecting to the normally store-scoped quotation page. Nothing
     * is switched or revealed unless the viewer may see the follow-up, holds
     * quotations.view and is a member of the quotation's active store.
     */
    public function openQuotation(Request $request, ActiveTenantContext $context, FollowUp $followUp): RedirectResponse
    {
        $this->authorize('view', $followUp);
        $user = $request->user();
        $quotation = $followUp->quotation_id === null ? null : Quotation::query()
            ->where('organization_id', $context->organizationOrFail()->getKey())
            ->whereKey($followUp->quotation_id)
            ->first(['id', 'organization_id', 'store_id']);
        $store = $quotation ? Store::query()->where('organization_id', $quotation->organization_id)->whereKey($quotation->store_id)->first() : null;

        $allowed = $store !== null
            && $store->status === 'active'
            && $user->hasPermission($context->organizationOrFail(), 'quotations.view')
            && $store->memberships()->where('user_id', $user->getKey())->exists();
        if (! $allowed) {
            return back()->withErrors(['quotation' => 'Ce devis n’est pas accessible depuis votre compte.']);
        }

        if ((int) $context->store()?->getKey() !== (int) $store->getKey()) {
            $context->activateStore($user, $store);
        }

        return redirect()->route('quotations.show', $quotation->getKey());
    }

    public function transition(Request $request, ActiveTenantContext $context, FollowUp $followUp, TransitionFollowUpAction $action): RedirectResponse
    {
        $this->authorize('update', $followUp);
        $action->execute($request->user(), $context->organizationOrFail(), $followUp, $request->only(['transition', 'outcome_note', 'expected_version']));

        return back()->with('success', 'Suivi mis à jour.');
    }
}
