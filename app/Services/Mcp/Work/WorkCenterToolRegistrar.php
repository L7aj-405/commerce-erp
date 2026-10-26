<?php

namespace App\Services\Mcp\Work;

use App\Services\Mcp\McpExecutionContext;
use Mcp\Schema\ToolAnnotations;

/**
 * W6 registration of the Work Center / Attention / Timeline tools. A tool is
 * listed only when the connection holds its scope AND the user currently holds
 * the ERP permission (McpExecutionContext::allows); each call re-checks both,
 * and the canonical services then apply record-level visibility. Registration
 * is the single place a future plan entitlement can filter tools.
 */
final class WorkCenterToolRegistrar
{
    private const SCOPED = 'Data is scoped to the connection user: only their visible stores, documents and work — never organization-wide.';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly WorkTools $work,
        private readonly AttentionTools $attention,
        private readonly TimelineTools $timeline,
    ) {}

    /** @param \Mcp\Server\Builder $builder @param array<string, mixed> $outputSchema */
    public function register($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $write = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);
        $versioned = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);
        $id = ['type' => 'integer', 'minimum' => 1];
        $page = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1];
        $perPage = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25];
        $date = ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
        $time = ['type' => 'string', 'pattern' => '^\\d{2}:\\d{2}$'];
        $version = ['type' => 'integer', 'minimum' => 1, 'description' => 'The `version` you last read; a stale version returns MCP_STALE_VERSION instead of overwriting newer work.'];
        $idempotency = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Reuse the same key when retrying: the follow-up is created once.'];
        $priority = ['type' => 'string', 'enum' => ['low', 'normal', 'high']];
        $due = [
            'due_date' => [...$date, 'description' => 'Organization-local calendar date (YYYY-MM-DD). Resolve "tomorrow" yourself.'],
            'due_time' => [...$time, 'description' => 'Organization-local time (HH:MM); requires due_date.'],
            'due_at' => ['type' => 'string', 'description' => 'Alternative to due_date/due_time: ISO 8601 with an explicit offset.'],
        ];
        $schema = fn (array $properties, array $required = []) => ['type' => 'object', ...($required === [] ? [] : ['required' => $required]),
            'properties' => $properties, 'additionalProperties' => false];

        if ($this->context->allows('follow_ups.view')) {
            $builder->addTool([$this->work, 'getMyWork'], name: 'get_my_work', title: 'Get my work',
                description: 'The connection user\'s own follow-ups ("what should I do today / what is overdue / coming up / done recently"). Buckets use the organization business timezone; counts give today/overdue/upcoming totals. '.self::SCOPED,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'bucket' => ['type' => 'string', 'enum' => WorkTools::BUCKETS, 'default' => 'today'],
                    'store_id' => $id, 'priority' => $priority, 'query' => ['type' => 'string', 'maxLength' => 120],
                    'page' => $page, 'per_page' => $perPage,
                ]));
        }

        if ($this->context->allows('follow_ups.create')) {
            $builder->addTool([$this->work, 'createFollowUp'], name: 'create_follow_up', title: 'Create follow-up',
                description: 'Create a follow-up (a task for a person). Store and customer are derived from the linked document; link at most one of quotation, sales order or invoice. Defaults to the connection user; assigning someone else or nobody needs the team scope. When acting on an attention item, prefer create_follow_up_from_attention.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'note' => ['type' => 'string', 'maxLength' => 5000],
                    'priority' => $priority, ...$due,
                    'customer_id' => $id, 'quotation_id' => $id, 'sales_order_id' => $id, 'invoice_id' => $id,
                    'assigned_user_id' => $id, 'unassigned' => ['type' => 'boolean', 'description' => 'Create as Unassigned (team scope required).'],
                    'idempotency_key' => $idempotency,
                ], ['title', 'idempotency_key']));
        }

        if ($this->context->allows('follow_ups.update')) {
            $builder->addTool([$this->work, 'updateFollowUp'], name: 'update_follow_up', title: 'Update follow-up',
                description: 'Edit the title/note/priority of an OPEN follow-up, reschedule it, or reassign it (team scope for someone else or nobody). Only supplied fields change. Use transition_follow_up to complete, cancel or reopen.',
                annotations: $versioned, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'follow_up_id' => $id, 'expected_version' => $version,
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'note' => ['type' => 'string', 'maxLength' => 5000],
                    'priority' => $priority, ...$due,
                    'assigned_user_id' => $id, 'unassigned' => ['type' => 'boolean'],
                ], ['follow_up_id', 'expected_version']));
            $builder->addTool([$this->work, 'transitionFollowUp'], name: 'transition_follow_up', title: 'Complete, cancel or reopen a follow-up',
                description: 'Explicit status transition of a follow-up: complete (optional outcome_note), cancel, or reopen.',
                annotations: $versioned, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'follow_up_id' => $id, 'transition' => ['type' => 'string', 'enum' => ['complete', 'cancel', 'reopen']],
                    'expected_version' => $version, 'outcome_note' => ['type' => 'string', 'maxLength' => 5000],
                ], ['follow_up_id', 'transition', 'expected_version']));
        }

        if ($this->context->allows('follow_ups.manage_all')) {
            $builder->addTool([$this->work, 'getTeamWork'], name: 'get_team_work', title: 'Get team workload',
                description: 'Managers: per-person operational workload (open, overdue, due today, upcoming, completed in the last 7 days, critical and escalated attention) plus Unassigned work. Not a performance score or ranking. '.self::SCOPED,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'store_id' => $id, 'query' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Filter people by name.'],
                    'scope' => ['type' => 'string', 'enum' => ['all', 'mine', 'unassigned']],
                    'signal' => ['type' => 'string', 'enum' => ['escalated', 'critical', 'warning', 'overdue', 'today', 'upcoming']],
                    'page' => $page,
                ]));
            $builder->addTool([$this->work, 'getTeamMemberWork'], name: 'get_team_member_work', title: 'Get a team member\'s work',
                description: 'Managers: the follow-ups of one team member (user id as string) or of "unassigned". People outside your team are not found.',
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'member' => ['type' => 'string', 'pattern' => '^(unassigned|[0-9]+)$'],
                    'bucket' => ['type' => 'string', 'enum' => WorkTools::BUCKETS, 'default' => 'overdue'],
                    'store_id' => $id, 'priority' => $priority, 'page' => $page, 'per_page' => $perPage,
                ], ['member']));
        }

        if ($this->context->allows('attention.view')) {
            $builder->addTool([$this->attention, 'getAttention'], name: 'get_attention', title: 'Get attention items',
                description: 'Detected situations on quotations, sales orders and invoices that may need a person (signals to decide on, never automatic commands). Ordered escalated critical > critical > warning > info. Without the team scope: your own and unassigned items. '.self::SCOPED,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'view' => ['type' => 'string', 'enum' => ['open', 'snoozed', 'dismissed', 'resolved', 'all'], 'default' => 'open'],
                    'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'critical']],
                    'domain' => ['type' => 'string', 'enum' => ['quotation', 'sales_order', 'invoice']],
                    'type' => ['type' => 'string', 'maxLength' => 64],
                    'assigned' => ['type' => 'string', 'pattern' => '^(mine|unassigned|mine_or_unassigned|all|[0-9]+)$', 'description' => '"all" or a user id needs the team scope.'],
                    'escalated' => ['type' => 'boolean'], 'store_id' => $id, 'customer_id' => $id,
                    'page' => $page, 'per_page' => $perPage,
                ]));
            $builder->addTool([$this->attention, 'explainAttention'], name: 'explain_attention', title: 'Explain an attention item',
                description: 'Why an item is flagged: the reason and the facts the detector recorded (dates, days, counts, remaining balance), its current state and the actions you may take now. Explain from these facts; do not infer conditions yourself.',
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema(['attention_item_id' => $id], ['attention_item_id']));
        }

        if ($this->context->allows('attention.view') && $this->context->allows('follow_ups.create')) {
            $builder->addTool([$this->attention, 'createFollowUpFromAttention'], name: 'create_follow_up_from_attention', title: 'Create follow-up from attention',
                description: 'Preferred way to act on an attention item: creates a follow-up linked to the item\'s own document and customer (never another document). Title and due date default to the item\'s suggestion. Does not resolve the item.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'attention_item_id' => $id, 'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'note' => ['type' => 'string', 'maxLength' => 5000], 'priority' => $priority,
                    'due_date' => $due['due_date'], 'due_time' => $due['due_time'], 'assigned_user_id' => $id,
                    'idempotency_key' => $idempotency,
                ], ['attention_item_id', 'idempotency_key']));
        }

        if ($this->context->allows('attention.update')) {
            $item = ['attention_item_id' => $id, 'expected_version' => $version];
            $builder->addTool([$this->attention, 'claimAttention'], name: 'claim_attention', title: 'Claim an attention item',
                description: 'Take ownership of an UNASSIGNED active attention item you can see.',
                annotations: $versioned, outputSchema: $outputSchema, inputSchema: $schema($item, ['attention_item_id', 'expected_version']));
            $builder->addTool([$this->attention, 'snoozeAttention'], name: 'snooze_attention', title: 'Snooze an attention item',
                description: 'Hide an active item until later; it reopens only if the condition still holds. Presets resolve in the organization timezone (09:00 local); "date" needs until_date.',
                annotations: $versioned, outputSchema: $outputSchema,
                inputSchema: $schema([...$item, 'preset' => ['type' => 'string', 'enum' => ['later_today', 'tomorrow', 'in_3_days', 'next_week', 'date']], 'until_date' => $date],
                    ['attention_item_id', 'expected_version', 'preset']));
            $builder->addTool([$this->attention, 'dismissAttention'], name: 'dismiss_attention', title: 'Dismiss an attention item',
                description: 'A person decides to ignore this episode of the condition (optional note). It does not change the document.',
                annotations: $versioned, outputSchema: $outputSchema,
                inputSchema: $schema([...$item, 'note' => ['type' => 'string', 'maxLength' => 500]], ['attention_item_id', 'expected_version']));

            if ($this->context->allows('follow_ups.manage_all')) {
                $builder->addTool([$this->attention, 'assignAttention'], name: 'assign_attention', title: 'Assign an attention item',
                    description: 'Managers: assign an active item to a person who can see its document and store, or unassign it (unassign=true). The new owner is notified once.',
                    annotations: $versioned, outputSchema: $outputSchema,
                    inputSchema: $schema([...$item, 'assigned_user_id' => $id, 'unassign' => ['type' => 'boolean']], ['attention_item_id', 'expected_version']));
            }
        }

        if ($this->context->allows('timeline.view')) {
            $builder->addTool([$this->timeline, 'getBusinessTimeline'], name: 'get_business_timeline', title: 'Get business timeline',
                description: 'What happened with a customer, quotation, sales order or invoice: recorded business events, newest first, with a stable cursor. Actor "system" means automatic; channel "mcp" means a person acting through an assistant. Amounts and email addresses appear only when the user may see them. '.self::SCOPED,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'subject_type' => ['type' => 'string', 'enum' => ['customer', 'quotation', 'sales_order', 'invoice']],
                    'subject_id' => $id, 'cursor' => ['type' => 'string', 'maxLength' => 200],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 25],
                    'category' => ['type' => 'string', 'enum' => ['documents', 'follow_ups', 'payments', 'attention']],
                ], ['subject_type', 'subject_id']));
        }
    }
}
