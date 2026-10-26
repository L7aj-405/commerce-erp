<?php

namespace App\Services\Mcp\Work;

use App\Actions\Attention\HandleAttentionItemAction;
use App\Models\AttentionItem;
use App\Services\AuditLogger;
use App\Services\Attention\AttentionQuery;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Mcp\Schema\Result\CallToolResult;

/**
 * W6 Attention over MCP — transport only. Visibility, ordering, presentation
 * and the "why" come from AttentionQuery; every action is
 * HandleAttentionItemAction (row lock, version check, ownership rules, audit,
 * assignment notification). Attention is a signal for a person to decide on:
 * nothing here acts on a document, and creating a follow-up never resolves
 * the item.
 *
 * Seeing the whole team's attention (`assigned` = all / a user id) and
 * assigning it to someone need the follow_ups.manage_all scope in addition to
 * the ERP role; otherwise the connection sees the user's own + claimable items.
 */
final class AttentionTools
{
    use RespondsToMcpTools;

    private const WITH = ['customer:id,organization_id,display_name', 'quotation:id,organization_id,store_id,quotation_number,status',
        'invoice:id,organization_id,store_id,invoice_number,status', 'salesOrder:id,organization_id,store_id,order_number,status', 'assignee:id,name'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly AttentionQuery $attention,
        private readonly HandleAttentionItemAction $actions,
        private readonly FollowUpQuery $followUps,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function getAttention(
        string $view = 'open',
        ?string $severity = null,
        ?string $domain = null,
        ?string $type = null,
        ?string $assigned = null,
        ?bool $escalated = null,
        ?int $store_id = null,
        ?int $customer_id = null,
        int $page = 1,
        int $per_page = 25,
    ): CallToolResult {
        return $this->execute('get_attention', function () use ($view, $severity, $domain, $type, $assigned, $escalated, $store_id, $customer_id, $page, $per_page) {
            $this->context->authorize('attention.view');
            $user = $this->context->user();
            $organization = $this->context->organization();
            // The attention.view scope already requires one document view permission; each item is then filtered by AttentionQuery.
            $assigned ??= $this->context->allows('follow_ups.manage_all') ? $this->attention->defaultAssigned($user, $organization) : 'mine_or_unassigned';
            if (! in_array($assigned, ['mine', 'unassigned', 'mine_or_unassigned'], true)) {
                $this->context->authorize('follow_ups.manage_all');
            }
            $filters = Validator::make(array_filter(compact('view', 'severity', 'domain', 'type', 'assigned', 'escalated', 'store_id', 'customer_id', 'page', 'per_page'),
                fn ($value) => $value !== null), AttentionQuery::rules())->validate();
            $result = $this->attention->search($user, $organization, $filters);
            $this->auditRead('mcp.attention.viewed', ['view' => $view, 'severity' => $severity, 'domain' => $domain, 'assigned' => $assigned, 'page' => $page, 'result_count' => count($result->items())]);

            return [
                'note' => 'Attention items are signals for a person to decide on, not commands. Only documents you may see are included.',
                'assigned' => $assigned,
                'items' => collect($result->items())->map(fn (AttentionItem $item) => $this->attention->present($user, $organization, $item))->all(),
                'pagination' => $this->pagination($result),
            ];
        });
    }

    public function explainAttention(int $attention_item_id): CallToolResult
    {
        return $this->execute('explain_attention', function () use ($attention_item_id) {
            $this->context->authorize('attention.view');
            $item = $this->visibleItem($attention_item_id);
            $this->auditRead('mcp.attention.explained', ['attention_item_id' => $attention_item_id]);

            return $this->attention->explain($this->context->user(), $this->context->organization(), $item);
        }, 'MCP_ATTENTION_NOT_FOUND');
    }

    public function claimAttention(int $attention_item_id, int $expected_version): CallToolResult
    {
        return $this->act('claim_attention', $attention_item_id, fn (AttentionItem $item) => $this->actions->claim(
            $this->context->user(), $this->context->organization(), $item, ['expected_version' => $expected_version]));
    }

    public function snoozeAttention(int $attention_item_id, int $expected_version, string $preset, ?string $until_date = null): CallToolResult
    {
        return $this->act('snooze_attention', $attention_item_id, fn (AttentionItem $item) => $this->actions->snooze(
            $this->context->user(), $this->context->organization(), $item,
            array_filter(['preset' => $preset, 'until_date' => $until_date, 'expected_version' => $expected_version], fn ($value) => $value !== null)));
    }

    public function dismissAttention(int $attention_item_id, int $expected_version, ?string $note = null): CallToolResult
    {
        return $this->act('dismiss_attention', $attention_item_id, fn (AttentionItem $item) => $this->actions->dismiss(
            $this->context->user(), $this->context->organization(), $item,
            array_filter(['note' => $note, 'expected_version' => $expected_version], fn ($value) => $value !== null)));
    }

    /** Manager: assign to a valid owner, or unassign (assigned_user_id omitted + unassign=true). */
    public function assignAttention(int $attention_item_id, int $expected_version, ?int $assigned_user_id = null, bool $unassign = false): CallToolResult
    {
        return $this->act('assign_attention', $attention_item_id, function (AttentionItem $item) use ($expected_version, $assigned_user_id, $unassign) {
            $this->context->authorize('follow_ups.manage_all');
            if ($assigned_user_id === null && ! $unassign) {
                throw new \InvalidArgumentException('assignee_required');
            }

            return $this->actions->assign($this->context->user(), $this->context->organization(), $item,
                ['assigned_user_id' => $unassign ? null : $assigned_user_id, 'expected_version' => $expected_version]);
        });
    }

    /**
     * The attention→follow-up bridge: the linked document, customer and store
     * always come from the attention item; the client never supplies a document.
     */
    public function createFollowUpFromAttention(
        int $attention_item_id,
        string $idempotency_key,
        ?string $title = null,
        ?string $note = null,
        ?string $priority = null,
        ?string $due_date = null,
        ?string $due_time = null,
        ?int $assigned_user_id = null,
    ): CallToolResult {
        return $this->execute('create_follow_up_from_attention', function () use ($attention_item_id, $idempotency_key, $title, $note, $priority, $due_date, $due_time, $assigned_user_id) {
            $this->context->authorize('attention.view');
            $this->context->authorize('follow_ups.create');
            if ($assigned_user_id !== null && $assigned_user_id !== (int) $this->context->user()->getKey()) {
                $this->context->authorize('follow_ups.manage_all');
            }
            $item = $this->visibleItem($attention_item_id);
            $presented = $this->attention->present($this->context->user(), $this->context->organization(), $item);
            $payload = array_filter([
                'title' => $title ?? $presented['suggestion']['title'], 'note' => $note, 'priority' => $priority,
                'due_date' => $due_date ?? ($due_time === null ? $presented['suggestion']['due_date'] : null), 'due_time' => $due_time,
                'assigned_user_id' => $assigned_user_id,
            ], fn ($value) => $value !== null);

            return $this->idempotency->execute('create_follow_up_from_attention', $idempotency_key, ['attention_item_id' => $attention_item_id, ...$payload],
                function () use ($item, $payload) {
                    $followUp = $this->actions->createFollowUp($this->context->user(), $this->context->organization(), $item, $payload);
                    $followUp->load(['assignee:id,name', 'customer:id,organization_id,display_name', 'quotation:id,organization_id,store_id,quotation_number,status',
                        'invoice:id,organization_id,store_id,invoice_number,status', 'salesOrder:id,organization_id,store_id,order_number,status']);

                    return ['target' => ['type' => 'follow_up', 'id' => (int) $followUp->getKey()],
                        'attention_item_id' => (int) $item->getKey(),
                        'follow_up' => $this->followUps->present($this->context->user(), $this->context->organization(), $followUp),
                        'note' => 'The attention item stays open until its business condition is gone; the follow-up does not resolve it.'];
                });
        }, 'MCP_ATTENTION_NOT_FOUND');
    }

    /** @param callable(AttentionItem): AttentionItem $action */
    private function act(string $tool, int $id, callable $action): CallToolResult
    {
        return $this->execute($tool, function () use ($id, $action) {
            $this->context->authorize('attention.update');
            $updated = $action($this->visibleItem($id));
            $updated->load(self::WITH);

            return ['target' => ['type' => 'attention_item', 'id' => $id],
                'attention' => $this->attention->present($this->context->user(), $this->context->organization(), $updated)];
        }, 'MCP_ATTENTION_NOT_FOUND');
    }

    /** An item the connection user may see, or "not found" (other stores / documents stay invisible). */
    private function visibleItem(int $id): AttentionItem
    {
        $item = AttentionItem::query()->where('organization_id', $this->context->organization()->getKey())->whereKey($id)->with(self::WITH)->first();
        if ($item === null || ! $this->attention->canView($this->context->user(), $item)) {
            throw (new ModelNotFoundException)->setModel(AttentionItem::class, [$id]);
        }

        return $item;
    }
}
