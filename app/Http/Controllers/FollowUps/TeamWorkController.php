<?php

namespace App\Http\Controllers\FollowUps;

use App\Enums\FollowUpStatus;
use App\Http\Controllers\Controller;
use App\Models\AttentionItem;
use App\Models\FollowUp;
use App\Services\ActiveTenantContext;
use App\Services\Attention\AttentionOwnership;
use App\Services\Attention\AttentionQuery;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\WorkCenter\TeamWorkQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** W5 Work Center "Team" (managers): transport only — aggregation lives in TeamWorkQuery, lists in FollowUpQuery / AttentionQuery. */
class TeamWorkController extends Controller
{
    private const TABS = ['overdue', 'today', 'upcoming', 'completed', 'all'];

    /** Open attention shown inline; the rest is one click away in Attention, filtered on the member. */
    private const ATTENTION_LIMIT = 50;

    public function index(Request $request, ActiveTenantContext $context, TeamWorkQuery $team, AttentionQuery $attention): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        abort_unless($team->canView($user, $organization), 403);
        $filters = array_filter($request->validate(TeamWorkQuery::rules()), fn ($value) => $value !== null && $value !== '');
        $overview = $team->overview($user, $organization, $filters);

        return Inertia::render('Work/Team', [
            'filters' => array_diff_key($filters, ['page' => true]),
            'summary' => $overview['summary'],
            'unassigned' => $overview['unassigned'],
            'members' => [
                'data' => $overview['members']->items(),
                'links' => $overview['members']->linkCollection()->toArray(),
                'total' => $overview['members']->total(),
            ],
            'stores' => $overview['stores'],
            'currentUserId' => (int) $user->getKey(),
            'canSeeAttention' => $attention->canViewAny($user, $organization),
        ]);
    }

    /** One member's (or, for `unassigned`, nobody's) operational work, through the canonical list queries. */
    public function show(Request $request, ActiveTenantContext $context, string $member, TeamWorkQuery $team, FollowUpQuery $followUps,
        AttentionQuery $attention, FollowUpAccess $access, AttentionOwnership $ownership): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        abort_unless($team->canView($user, $organization), 403);
        abort_unless($member === 'unassigned' || ctype_digit($member), 404);
        $filters = array_filter($request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'priority' => FollowUpQuery::rules()['priority'],
            'date_from' => FollowUpQuery::rules()['date_from'],
            'date_to' => FollowUpQuery::rules()['date_to'],
            'severity' => AttentionQuery::rules()['severity'],
            'escalated' => ['nullable', 'boolean'],
        ]), fn ($value) => $value !== null && $value !== '');
        $row = $team->member($user, $organization, $member === 'unassigned' ? 0 : (int) $member, isset($filters['store_id']) ? (int) $filters['store_id'] : null);
        abort_if($row === null, 404);
        $assignee = $member === 'unassigned' ? 'unassigned' : (string) $row['id'];
        $tab = $filters['tab'] ?? 'overdue';

        $followUpFilters = [...array_intersect_key($filters, array_flip(['store_id', 'priority', 'date_from', 'date_to'])), 'assigned_to' => $assignee];
        if (in_array($tab, FollowUpQuery::DUE_BUCKETS, true)) {
            $followUpFilters['due'] = $tab;
        } elseif ($tab === 'completed') {
            $followUpFilters['status'] = FollowUpStatus::Completed->value;
        }
        $page = $followUps->search($user, $organization, $followUpFilters);

        $items = null;
        if ($attention->canViewAny($user, $organization)) {
            $attentionPage = $attention->search($user, $organization, [
                ...array_intersect_key($filters, array_flip(['store_id', 'severity', 'escalated'])), 'view' => 'open', 'assigned' => $assignee, 'per_page' => self::ATTENTION_LIMIT,
            ]);
            $items = [
                'data' => collect($attentionPage->items())->map(fn (AttentionItem $item) => $attention->present($user, $organization, $item))->all(),
                'total' => $attentionPage->total(),
            ];
        }

        return Inertia::render('Work/TeamMember', [
            'member' => $row,
            'tab' => $tab,
            'filters' => array_diff_key($filters, ['tab' => true]),
            'followUps' => [
                'data' => collect($page->items())->map(fn (FollowUp $followUp) => $followUps->present($user, $organization, $followUp))->all(),
                'links' => $page->linkCollection()->toArray(),
                'total' => $page->total(),
            ],
            'attention' => $items,
            'context' => $followUps->formContext($user, $organization),
            'owners' => $items === null ? [] : $ownership->assignmentOptions($organization),
            'stores' => $team->stores($user, $organization),
            'completedDays' => FollowUpQuery::RECENT_COMPLETED_DAYS,
        ]);
    }
}
