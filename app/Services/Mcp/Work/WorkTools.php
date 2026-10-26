<?php

namespace App\Services\Mcp\Work;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Services\AuditLogger;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\WorkCenter\TeamWorkQuery;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Mcp\Schema\Result\CallToolResult;

/**
 * W6 "My Work" and team workload over MCP — transport only. Reads go through
 * FollowUpQuery / TeamWorkQuery (canonical buckets, visibility, presentation),
 * writes through SaveFollowUpAction / TransitionFollowUpAction (store
 * derivation, link/assignee rules, timezone parsing, versions, audit). The
 * organization is always the connection's; every call needs the MCP scope
 * AND the user's current ERP permission (McpExecutionContext::authorize), and
 * the canonical services then apply the user's own visibility.
 *
 * Assigning work to someone else (or to nobody) additionally needs the
 * follow_ups.manage_all scope: a connection never carries managerial power the
 * user did not grant it, even if the ERP role has it.
 */
final class WorkTools
{
    use RespondsToMcpTools;

    public const BUCKETS = ['today', 'overdue', 'upcoming', 'completed', 'all'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly FollowUpQuery $followUps,
        private readonly FollowUpAccess $access,
        private readonly SaveFollowUpAction $save,
        private readonly TransitionFollowUpAction $transitions,
        private readonly TeamWorkQuery $team,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function getMyWork(string $bucket = 'today', ?int $store_id = null, ?string $priority = null, ?string $query = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('get_my_work', function () use ($bucket, $store_id, $priority, $query, $page, $per_page) {
            $this->context->authorize('follow_ups.view');
            $user = $this->context->user();
            $organization = $this->context->organization();
            $result = $this->list($bucket, 'me', compact('store_id', 'priority', 'query', 'page', 'per_page'));
            $this->auditRead('mcp.work.viewed', ['bucket' => $bucket, 'store_id' => $store_id, 'page' => $page, 'result_count' => count($result['items'])]);

            return [
                'context' => $this->businessContext(),
                'counts' => $this->followUps->counts($user, $organization, 'me'),
                ...$result,
            ];
        });
    }

    public function createFollowUp(
        string $title,
        string $idempotency_key,
        ?string $note = null,
        ?string $priority = null,
        ?string $due_date = null,
        ?string $due_time = null,
        ?string $due_at = null,
        ?int $customer_id = null,
        ?int $quotation_id = null,
        ?int $sales_order_id = null,
        ?int $invoice_id = null,
        ?int $assigned_user_id = null,
        bool $unassigned = false,
    ): CallToolResult {
        return $this->execute('create_follow_up', function () use ($title, $idempotency_key, $note, $priority, $due_date, $due_time, $due_at, $customer_id, $quotation_id, $sales_order_id, $invoice_id, $assigned_user_id, $unassigned) {
            $this->context->authorize('follow_ups.create');
            $payload = array_filter(compact('title', 'note', 'priority', 'due_date', 'due_time', 'due_at', 'customer_id', 'quotation_id', 'sales_order_id', 'invoice_id', 'assigned_user_id'),
                fn ($value) => $value !== null);
            $payload = $this->assignment($payload, $assigned_user_id, $unassigned);

            return $this->idempotency->execute('create_follow_up', $idempotency_key, $payload, function () use ($payload) {
                $followUp = $this->save->create($this->context->user(), $this->context->organization(), $payload);

                return ['target' => ['type' => 'follow_up', 'id' => (int) $followUp->getKey()], 'follow_up' => $this->present($followUp)];
            });
        });
    }

    public function updateFollowUp(
        int $follow_up_id,
        int $expected_version,
        ?string $title = null,
        ?string $note = null,
        ?string $priority = null,
        ?string $due_date = null,
        ?string $due_time = null,
        ?string $due_at = null,
        ?int $assigned_user_id = null,
        bool $unassigned = false,
    ): CallToolResult {
        return $this->execute('update_follow_up', function () use ($follow_up_id, $expected_version, $title, $note, $priority, $due_date, $due_time, $due_at, $assigned_user_id, $unassigned) {
            $this->context->authorize('follow_ups.update');
            $followUp = $this->visibleFollowUp($follow_up_id);
            $payload = array_filter(compact('title', 'note', 'priority', 'due_date', 'due_time', 'due_at', 'assigned_user_id'), fn ($value) => $value !== null);
            $payload = [...$this->assignment($payload, $assigned_user_id, $unassigned), 'expected_version' => $expected_version];

            return ['target' => ['type' => 'follow_up', 'id' => $follow_up_id],
                'follow_up' => $this->present($this->save->update($this->context->user(), $this->context->organization(), $followUp, $payload))];
        }, 'MCP_FOLLOW_UP_NOT_FOUND');
    }

    public function transitionFollowUp(int $follow_up_id, string $transition, int $expected_version, ?string $outcome_note = null): CallToolResult
    {
        return $this->execute('transition_follow_up', function () use ($follow_up_id, $transition, $expected_version, $outcome_note) {
            $this->context->authorize('follow_ups.update');
            $followUp = $this->visibleFollowUp($follow_up_id);
            $data = array_filter(['transition' => $transition, 'outcome_note' => $outcome_note, 'expected_version' => $expected_version], fn ($value) => $value !== null);

            return ['target' => ['type' => 'follow_up', 'id' => $follow_up_id],
                'follow_up' => $this->present($this->transitions->execute($this->context->user(), $this->context->organization(), $followUp, $data))];
        }, 'MCP_FOLLOW_UP_NOT_FOUND');
    }

    public function getTeamWork(?int $store_id = null, ?string $query = null, ?string $scope = null, ?string $signal = null, int $page = 1): CallToolResult
    {
        return $this->execute('get_team_work', function () use ($store_id, $query, $scope, $signal, $page) {
            $this->context->authorize('follow_ups.manage_all');
            $user = $this->context->user();
            $organization = $this->context->organization();
            $this->access->authorize($this->team->canView($user, $organization));
            $filters = Validator::make(array_filter(compact('store_id', 'query', 'scope', 'signal', 'page'), fn ($value) => $value !== null), TeamWorkQuery::rules())->validate();
            $overview = $this->team->overview($user, $organization, $filters);
            $this->auditRead('mcp.team.viewed', ['store_id' => $store_id, 'scope' => $scope, 'signal' => $signal, 'page' => $page]);

            return [
                'context' => $this->businessContext(),
                'definition' => 'Team = active members who share one of your active stores (or have no store), plus anyone holding work you can see. Counts are operational workload, not performance.',
                'order' => 'Escalated attention, then critical attention, then overdue work, then work due today, then the rest; within a group by that count, then name.',
                'summary' => $overview['summary'],
                'unassigned' => $this->teamRow($overview['unassigned']),
                'members' => array_map(fn (array $row) => $this->teamRow($row), $overview['members']->items()),
                'stores' => $overview['stores'],
                'pagination' => $this->pagination($overview['members']),
            ];
        });
    }

    public function getTeamMemberWork(string $member, string $bucket = 'overdue', ?int $store_id = null, ?string $priority = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('get_team_member_work', function () use ($member, $bucket, $store_id, $priority, $page, $per_page) {
            $this->context->authorize('follow_ups.manage_all');
            $user = $this->context->user();
            $organization = $this->context->organization();
            $this->access->authorize($this->team->canView($user, $organization));
            if ($member !== 'unassigned' && ! ctype_digit($member)) {
                throw new \InvalidArgumentException('invalid_member');
            }
            // Someone outside the viewer's team (e.g. only in a store the viewer lacks) is simply "not found".
            $row = $this->team->member($user, $organization, $member === 'unassigned' ? 0 : (int) $member, $store_id)
                ?? throw (new ModelNotFoundException)->setModel(FollowUp::class);
            $result = $this->list($bucket, $member === 'unassigned' ? 'unassigned' : (string) $row['id'], compact('store_id', 'priority', 'page', 'per_page'));
            $this->auditRead('mcp.team_member.viewed', ['member' => $member, 'bucket' => $bucket, 'store_id' => $store_id, 'page' => $page]);

            return ['context' => $this->businessContext(), 'member' => $this->teamRow($row), ...$result];
        }, 'MCP_TEAM_MEMBER_NOT_FOUND');
    }

    /** One bucket of follow-ups through the canonical search. @param array<string, mixed> $filters @return array{bucket: string, items: list<array<string, mixed>>, pagination: array<string, mixed>} */
    private function list(string $bucket, string $assignedTo, array $filters): array
    {
        if (! in_array($bucket, self::BUCKETS, true)) {
            throw new \InvalidArgumentException('invalid_bucket');
        }
        $filters = array_filter([...$filters, 'assigned_to' => $assignedTo], fn ($value) => $value !== null);
        if (in_array($bucket, FollowUpQuery::DUE_BUCKETS, true)) {
            $filters['due'] = $bucket;
        } elseif ($bucket === 'completed') {
            $filters['status'] = FollowUpStatus::Completed->value;
        }
        $filters = Validator::make($filters, FollowUpQuery::rules())->validate();
        $page = $this->followUps->search($this->context->user(), $this->context->organization(), $filters);

        return [
            'bucket' => $bucket,
            'items' => collect($page->items())->map(fn (FollowUp $followUp) => $this->present($followUp))->all(),
            'pagination' => $this->pagination($page),
        ];
    }

    /** A follow-up the connection user may see, or "not found" (never reveals other stores/users' work). */
    private function visibleFollowUp(int $id): FollowUp
    {
        $followUp = FollowUp::query()->where('organization_id', $this->context->organization()->getKey())->whereKey($id)->first();
        if ($followUp === null || ! $this->access->canView($this->context->user(), $followUp)) {
            throw (new ModelNotFoundException)->setModel(FollowUp::class, [$id]);
        }

        return $followUp;
    }

    /** Assigning to someone else or to nobody needs the manager scope too. @param array<string, mixed> $payload @return array<string, mixed> */
    private function assignment(array $payload, ?int $assignedUserId, bool $unassigned): array
    {
        if ($unassigned) {
            $payload['assigned_user_id'] = null;
        }
        if ($unassigned || ($assignedUserId !== null && $assignedUserId !== (int) $this->context->user()->getKey())) {
            $this->context->authorize('follow_ups.manage_all');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function present(FollowUp $followUp): array
    {
        $followUp->loadMissing(['assignee:id,name', 'customer:id,organization_id,display_name', 'quotation:id,organization_id,store_id,quotation_number,status',
            'invoice:id,organization_id,store_id,invoice_number,status', 'salesOrder:id,organization_id,store_id,order_number,status']);

        return $this->followUps->present($this->context->user(), $this->context->organization(), $followUp);
    }

    /** No hidden score: the internal ordering tier stays server-side. @param array<string, mixed> $row @return array<string, mixed> */
    private function teamRow(array $row): array
    {
        return array_diff_key([...$row, 'member' => $row['id'] === 0 ? 'unassigned' : (string) $row['id']], ['tier' => true, 'tier_count' => true]);
    }

    /** @return array{today: string, timezone: string, timezone_configured: bool} */
    private function businessContext(): array
    {
        $organization = $this->context->organization();

        return ['today' => BusinessTime::today($organization), 'timezone' => $organization->businessTimezone(), 'timezone_configured' => $organization->hasConfiguredTimezone()];
    }
}
