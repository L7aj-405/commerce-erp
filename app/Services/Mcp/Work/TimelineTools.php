<?php

namespace App\Services\Mcp\Work;

use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Timeline\BusinessTimeline;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Mcp\Schema\Result\CallToolResult;

/**
 * W6 business activity timeline over MCP — transport only. BusinessTimeline
 * decides everything (root visibility, the same document universe as the
 * customer profile via DocumentVisibility, permission-gated amounts and email
 * addresses, stable cursor); this tool only drops the web-only navigation
 * links, which an MCP client cannot follow.
 */
final class TimelineTools
{
    use RespondsToMcpTools;

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly BusinessTimeline $timeline,
        private readonly AuditLogger $audit,
    ) {}

    public function getBusinessTimeline(string $subject_type, int $subject_id, ?string $cursor = null, int $limit = 25, ?string $category = null): CallToolResult
    {
        return $this->execute('get_business_timeline', function () use ($subject_type, $subject_id, $cursor, $limit, $category) {
            $this->context->authorize('timeline.view');
            Validator::make(['subject_type' => $subject_type], ['subject_type' => ['required', Rule::in(BusinessTimeline::SUBJECTS)]])->validate();
            $filters = Validator::make(array_filter(['cursor' => $cursor, 'per_page' => $limit, 'category' => $category], fn ($value) => $value !== null),
                BusinessTimeline::rules())->validate();
            $page = $this->timeline->page($this->context->user(), $this->context->organization(), $subject_type, $subject_id, $filters)
                ?? throw (new ModelNotFoundException)->setModel(Customer::class, [$subject_id]);
            $this->auditRead('mcp.timeline.viewed', ['subject_type' => $subject_type, 'subject_id' => $subject_id, 'category' => $category, 'has_cursor' => $cursor !== null]);

            return [
                'subject' => ['type' => $subject_type, 'id' => $subject_id],
                'timezone' => $page['timezone'],
                'actor_types' => ['user' => 'a person (channel "mcp" = acting through an assistant connection)', 'system' => 'automatic, nobody acted'],
                'entries' => array_map(fn (array $entry) => [...array_diff_key($entry, ['link' => true]),
                    'occurred_at_local' => BusinessTime::toLocal($this->context->organization(), CarbonImmutable::parse($entry['occurred_at']))->toIso8601String()], $page['entries']),
                'next_cursor' => $page['next_cursor'],
            ];
        }, 'MCP_TIMELINE_SUBJECT_NOT_FOUND');
    }
}
