<?php

namespace Tests\Feature\Mcp;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\AttentionItem;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Sales\SalesAssistantTools;
use App\Services\Mcp\Work\AttentionTools;
use App\Services\Mcp\Work\TimelineTools;
use App\Services\Mcp\Work\WorkTools;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuotationTestCase;

/** W6: Work Center, Attention, Team and Timeline over MCP — canonical services, scope ∩ ERP permission ∩ store. */
class McpWorkCenterToolsTest extends QuotationTestCase
{
    private const SELLER = ['follow_ups.view', 'follow_ups.create', 'follow_ups.update', 'quotations.view', 'sales_orders.view', 'invoices.view', 'customers.view'];

    private const MANAGER = [...self::SELLER, 'follow_ups.manage_all', 'payments.view'];

    private const ALL_SCOPES = ['follow_ups.view', 'follow_ups.create', 'follow_ups.update', 'follow_ups.manage_all', 'attention.view', 'attention.update', 'timeline.view', 'customers.view'];

    private int $sequence = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Organization, Store, Store, User} organization (Casablanca), Store A, Store B, owner */
    private function fixture(): array
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $owner = User::factory()->create(['name' => 'Owner']);
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => 'Africa/Casablanca', 'allow_mcp_connections' => true];
        $organization->save();
        $storeA = $this->createStore($organization, $owner, 'Store A');
        $storeB = $this->createStore($organization, $owner, 'Store B');

        return [$organization->fresh(), $storeA, $storeB, $owner];
    }

    /** @param list<Store> $stores */
    private function member(Organization $organization, array $stores, array $permissions, string $name = 'Member'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->addOrganizationMember($organization, $user, $permissions);
        foreach ($stores as $store) {
            $this->addStoreMember($store, $user);
        }

        return $user;
    }

    /** Bind a fresh MCP connection for this user (fresh scoped services, as on a new request). */
    private function connect(User $user, Organization $organization, array $scopes = self::ALL_SCOPES): void
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @return array<string, mixed> structured content */
    private function tool(string $class, string $method, array $arguments = []): array
    {
        return app($class)->{$method}(...$arguments)->structuredContent;
    }

    private function followUp(Organization $organization, ?Store $store, ?User $assignee, array $attributes = []): int
    {
        return DB::table('follow_ups')->insertGetId([
            'organization_id' => $organization->getKey(), 'store_id' => $store?->getKey(), 'assigned_user_id' => $assignee?->getKey(),
            'title' => 'Suivi '.(++$this->sequence), 'priority' => 'normal', 'status' => 'open', 'due_date' => '2026-11-10', 'due_at' => null,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(), ...$attributes,
        ]);
    }

    private function order(User $owner, Organization $organization, Store $store, ?Customer $customer = null): SalesOrder
    {
        $this->activate($owner, $organization, $store);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '100.0000']);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }

    private function attention(Organization $organization, SalesOrder $order, ?User $assignee, string $severity = 'critical'): AttentionItem
    {
        $id = DB::table('attention_items')->insertGetId([
            'organization_id' => $organization->getKey(), 'store_id' => $order->store_id, 'type' => 'sales_order_unpaid_overdue',
            'subject_type' => 'sales_order', 'subject_id' => $order->getKey(), 'sales_order_id' => $order->getKey(), 'customer_id' => $order->customer_id,
            'episode' => 1, 'active_key' => 'k:'.(++$this->sequence), 'severity' => $severity, 'status' => 'open', 'title' => 'Commande impayée',
            'summary' => 'Échéance dépassée depuis le 01/11/2026 (9 j), aucun paiement.',
            'metadata' => json_encode(['document_number' => $order->order_number, 'facts' => ['date' => '2026-11-01', 'days' => 9]]),
            'assigned_user_id' => $assignee?->getKey(), 'detected_at' => now(), 'last_detected_at' => now(), 'critical_since' => now(),
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return AttentionItem::query()->findOrFail($id);
    }

    public function test_scope_and_current_erp_permission_are_both_required_and_never_widened(): void
    {
        [$organization, $storeA] = $this->fixture();
        $seller = $this->member($organization, [$storeA], self::SELLER);

        $this->connect($seller, $organization, ['follow_ups.view']);
        $this->assertTrue($this->tool(WorkTools::class, 'getMyWork')['ok']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool(WorkTools::class, 'createFollowUp', ['title' => 'X', 'idempotency_key' => 'k1'])['error']['code'], 'Missing scope.');
        $this->assertSame('MCP_FORBIDDEN', $this->tool(WorkTools::class, 'getTeamWork')['error']['code']);

        // A team scope on a non-manager grants nothing.
        $this->connect($seller, $organization);
        $this->assertSame('MCP_FORBIDDEN', $this->tool(WorkTools::class, 'getTeamWork')['error']['code']);

        // Revoked ERP permission / suspended membership: the scope alone is worthless.
        OrganizationMembership::query()->where('user_id', $seller->getKey())->update(['status' => 'suspended']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool(WorkTools::class, 'getMyWork')['error']['code']);
    }

    public function test_my_work_buckets_visibility_pagination_and_cross_org_ids(): void
    {
        [$organization, $storeA, $storeB, $owner] = $this->fixture();
        $seller = $this->member($organization, [$storeA], self::SELLER);
        $this->followUp($organization, $storeA, $seller, ['due_date' => '2026-11-09']);                                   // overdue
        $this->followUp($organization, $storeA, $seller, ['due_date' => '2026-11-10', 'due_at' => '2026-11-10 21:30:00']);  // today (22:30 local)
        for ($i = 0; $i < 3; $i++) {
            $this->followUp($organization, null, $seller, ['due_date' => '2026-11-12']);                                   // upcoming
        }
        $this->followUp($organization, $storeB, $seller, ['due_date' => '2026-11-09']);   // Store B: not a member
        $this->followUp($organization, $storeA, $owner, ['due_date' => '2026-11-09']);    // someone else's work

        $this->connect($seller, $organization);
        $today = $this->tool(WorkTools::class, 'getMyWork')['data'];
        $this->assertSame(['today' => 1, 'overdue' => 1, 'upcoming' => 3], $today['counts']);
        $this->assertSame('2026-11-10', $today['context']['today']);
        $this->assertSame('22:30', $today['items'][0]['due_time'], 'Organization-local time.');
        $page = $this->tool(WorkTools::class, 'getMyWork', ['bucket' => 'upcoming', 'per_page' => 2, 'page' => 2])['data'];
        $this->assertSame([1, 3, false], [count($page['items']), $page['pagination']['total'], $page['pagination']['has_more']]);
        $this->assertSame('MCP_INVALID_ARGUMENTS', $this->tool(WorkTools::class, 'getMyWork', ['bucket' => 'everything'])['error']['code']);

        // Another organization's follow-up id is simply not found.
        $stranger = User::factory()->create();
        $other = $this->createOrganization($stranger, 'Other');
        $foreign = $this->followUp($other, null, $stranger);
        $this->assertSame('MCP_FOLLOW_UP_NOT_FOUND', $this->tool(WorkTools::class, 'transitionFollowUp',
            ['follow_up_id' => $foreign, 'transition' => 'complete', 'expected_version' => 1])['error']['code']);
    }

    public function test_follow_up_writes_are_canonical_idempotent_version_checked_and_audited_as_mcp(): void
    {
        [$organization, $storeA, , $owner] = $this->fixture();
        $customer = $this->createCustomer($organization, 'ACME');
        $order = $this->order($owner, $organization, $storeA, $customer);
        $seller = $this->member($organization, [$storeA], self::SELLER);
        $this->connect($seller, $organization);

        $arguments = ['title' => 'Relancer ACME', 'idempotency_key' => 'create-1', 'sales_order_id' => (int) $order->getKey(), 'due_date' => '2026-11-11', 'due_time' => '09:00'];
        $created = $this->tool(WorkTools::class, 'createFollowUp', $arguments)['data'];
        $replay = $this->tool(WorkTools::class, 'createFollowUp', $arguments)['data'];
        $this->assertFalse($created['idempotent_replay']);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame($created['target']['id'], $replay['target']['id']);
        $this->assertSame(1, FollowUp::query()->where('title', 'Relancer ACME')->count(), 'A retried create never duplicates.');
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $this->tool(WorkTools::class, 'createFollowUp', [...$arguments, 'title' => 'Autre'])['error']['code']);
        $followUp = $created['follow_up'];
        $this->assertSame([(int) $storeA->getKey(), 'ACME', '09:00'], [$followUp['store_id'], $followUp['customer']['name'], $followUp['due_time']], 'Store and customer derived from the order.');

        $moved = $this->tool(WorkTools::class, 'updateFollowUp', ['follow_up_id' => $followUp['id'], 'expected_version' => 1, 'due_date' => '2026-11-13'])['data']['follow_up'];
        $this->assertSame(['2026-11-13', 2], [$moved['due_date'], $moved['version']]);
        $this->assertSame('MCP_STALE_VERSION', $this->tool(WorkTools::class, 'updateFollowUp', ['follow_up_id' => $followUp['id'], 'expected_version' => 1, 'title' => 'Écrasé'])['error']['code']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool(WorkTools::class, 'updateFollowUp', ['follow_up_id' => $followUp['id'], 'expected_version' => 2, 'assigned_user_id' => (int) $owner->getKey()])['error']['code'],
            'Reassigning to someone else needs the team scope and manage_all.');

        $done = $this->tool(WorkTools::class, 'transitionFollowUp', ['follow_up_id' => $followUp['id'], 'transition' => 'complete', 'expected_version' => 2, 'outcome_note' => 'Payé'])['data']['follow_up'];
        $this->assertSame('completed', $done['status']);
        $this->assertSame('MCP_INVALID_STATE', $this->tool(WorkTools::class, 'updateFollowUp', ['follow_up_id' => $followUp['id'], 'expected_version' => 3, 'title' => 'Trop tard'])['error']['code']);

        $log = AuditLog::query()->where('event', 'follow_up.completed')->latest('id')->firstOrFail();
        $this->assertSame([(int) $seller->getKey(), 'mcp'], [(int) $log->actor_id, $log->actor_channel]);
        $this->assertNotNull($log->mcp_connection_id);
    }

    public function test_attention_listing_explain_actions_and_follow_up_bridge(): void
    {
        [$organization, $storeA, $storeB, $owner] = $this->fixture();
        $seller = $this->member($organization, [$storeA], self::SELLER);
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $orderA = $this->order($owner, $organization, $storeA, $this->createCustomer($organization, 'ACME'));
        $orderB = $this->order($owner, $organization, $storeB);
        $critical = $this->attention($organization, $orderA, null);
        $warning = $this->attention($organization, $this->order($owner, $organization, $storeA), $seller, 'warning');
        $hidden = $this->attention($organization, $orderB, null);

        $this->connect($seller, $organization);
        $list = $this->tool(AttentionTools::class, 'getAttention')['data'];
        $this->assertSame('mine_or_unassigned', $list['assigned']);
        $this->assertSame([(int) $critical->getKey(), (int) $warning->getKey()], array_column($list['items'], 'id'), 'Critical first; Store B invisible.');
        $this->assertSame('MCP_FORBIDDEN', $this->tool(AttentionTools::class, 'getAttention', ['assigned' => 'all'])['error']['code'], 'Team view needs the team scope + role.');
        $this->assertSame('MCP_ATTENTION_NOT_FOUND', $this->tool(AttentionTools::class, 'explainAttention', ['attention_item_id' => (int) $hidden->getKey()])['error']['code']);

        $why = $this->tool(AttentionTools::class, 'explainAttention', ['attention_item_id' => (int) $critical->getKey()])['data'];
        $this->assertSame(['date' => '2026-11-01', 'days' => 9], $why['facts']);
        $this->assertSame('sales order payment due date', $why['facts_meaning']['date']);
        $this->assertSame(['claim', 'snooze', 'dismiss', 'create_follow_up'], $why['allowed_actions']);

        $claimed = $this->tool(AttentionTools::class, 'claimAttention', ['attention_item_id' => (int) $critical->getKey(), 'expected_version' => 1])['data']['attention'];
        $this->assertSame((int) $seller->getKey(), $claimed['assignee']['id']);
        $this->assertSame('MCP_STALE_VERSION', $this->tool(AttentionTools::class, 'snoozeAttention', ['attention_item_id' => (int) $critical->getKey(), 'expected_version' => 1, 'preset' => 'tomorrow'])['error']['code']);
        $snoozed = $this->tool(AttentionTools::class, 'snoozeAttention', ['attention_item_id' => (int) $critical->getKey(), 'expected_version' => 2, 'preset' => 'tomorrow'])['data']['attention'];
        $this->assertSame('snoozed', $snoozed['status']);
        $this->assertSame('dismissed', $this->tool(AttentionTools::class, 'dismissAttention', ['attention_item_id' => (int) $warning->getKey(), 'expected_version' => 1, 'note' => 'Accord oral'])['data']['attention']['status']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool(AttentionTools::class, 'assignAttention', ['attention_item_id' => (int) $critical->getKey(), 'expected_version' => 3, 'assigned_user_id' => (int) $manager->getKey()])['error']['code']);

        // Bridge: the document always comes from the item; idempotent; the item stays open.
        $bridge = ['attention_item_id' => (int) $critical->getKey(), 'idempotency_key' => 'bridge-1', 'due_date' => '2026-11-11', 'due_time' => '09:00'];
        $created = $this->tool(AttentionTools::class, 'createFollowUpFromAttention', $bridge)['data'];
        $this->assertSame((int) $orderA->getKey(), $created['follow_up']['sales_order']['id']);
        $this->assertSame((int) $orderA->getKey(), (int) FollowUp::query()->findOrFail($created['target']['id'])->sales_order_id);
        $this->assertTrue($this->tool(AttentionTools::class, 'createFollowUpFromAttention', $bridge)['data']['idempotent_replay']);
        $this->assertSame(1, FollowUp::query()->where('sales_order_id', $orderA->getKey())->count());
        $this->assertSame([(int) $created['target']['id']], $critical->fresh()->metadata['follow_up_ids']);
        $this->assertNotNull($critical->fresh()->active_key, 'Creating a follow-up never resolves the attention.');

        // A manager with the team scope may assign.
        $this->connect($manager, $organization);
        $assigned = $this->tool(AttentionTools::class, 'assignAttention', ['attention_item_id' => (int) $critical->getKey(), 'expected_version' => (int) $critical->fresh()->version, 'assigned_user_id' => (int) $manager->getKey()]);
        $this->assertSame((int) $manager->getKey(), $assigned['data']['attention']['assignee']['id']);
    }

    public function test_team_tools_are_manager_only_store_scoped_and_score_free(): void
    {
        [$organization, $storeA, $storeB] = $this->fixture();
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $peer = $this->member($organization, [$storeA], self::SELLER, 'Peer A');
        $onlyB = $this->member($organization, [$storeB], self::SELLER, 'Only B');
        $this->followUp($organization, $storeA, $peer, ['due_date' => '2026-11-01']);
        $this->followUp($organization, $storeB, $onlyB, ['due_date' => '2026-11-01']);

        $this->connect($manager, $organization);
        $team = $this->tool(WorkTools::class, 'getTeamWork')['data'];
        $names = array_column($team['members'], 'name');
        $this->assertContains('Peer A', $names);
        $this->assertNotContains('Only B', $names);
        foreach ($team['members'] as $row) {
            $this->assertArrayNotHasKey('tier', $row);
            $this->assertArrayNotHasKey('score', $row);
        }
        $this->assertSame(1, $this->tool(WorkTools::class, 'getTeamMemberWork', ['member' => (string) $peer->getKey()])['data']['pagination']['total']);
        $this->assertSame('MCP_TEAM_MEMBER_NOT_FOUND', $this->tool(WorkTools::class, 'getTeamMemberWork', ['member' => (string) $onlyB->getKey()])['error']['code']);
    }

    public function test_timeline_and_customer_balance_follow_the_users_store_universe(): void
    {
        [$organization, $storeA, $storeB, $owner] = $this->fixture();
        $customer = $this->createCustomer($organization, 'ACME');
        $orderA = $this->order($owner, $organization, $storeA, $customer);
        $orderB = $this->order($owner, $organization, $storeB, $customer);
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $orderB->fresh(), $account, '10.0000');
        $noFinance = $this->member($organization, [$storeA], self::SELLER);

        $this->connect($noFinance, $organization, ['timeline.view', 'customers.view']);
        $timeline = $this->tool(TimelineTools::class, 'getBusinessTimeline', ['subject_type' => 'customer', 'subject_id' => (int) $customer->getKey()])['data'];
        $payload = json_encode($timeline);
        $this->assertStringNotContainsString((string) $orderB->order_number, $payload);
        $this->assertStringContainsString((string) $orderA->order_number, $payload);
        foreach ($timeline['entries'] as $entry) {
            $this->assertArrayNotHasKey('link', $entry);
            $this->assertArrayHasKey('occurred_at_local', $entry);
        }
        $this->assertSame('MCP_TIMELINE_SUBJECT_NOT_FOUND', $this->tool(TimelineTools::class, 'getBusinessTimeline', ['subject_type' => 'sales_order', 'subject_id' => (int) $orderB->getKey()])['error']['code']);
        $this->assertSame('MCP_VALIDATION_FAILED', $this->tool(TimelineTools::class, 'getBusinessTimeline', ['subject_type' => 'sales_order', 'subject_id' => (int) $orderA->getKey(), 'cursor' => 'nope'])['error']['code']);

        // Stable cursor over the canonical timeline.
        $first = $this->tool(TimelineTools::class, 'getBusinessTimeline', ['subject_type' => 'sales_order', 'subject_id' => (int) $orderA->getKey(), 'limit' => 1])['data'];
        $second = $this->tool(TimelineTools::class, 'getBusinessTimeline', ['subject_type' => 'sales_order', 'subject_id' => (int) $orderA->getKey(), 'limit' => 1, 'cursor' => $first['next_cursor']])['data'];
        $this->assertNotSame($first['entries'][0]['id'], $second['entries'][0]['id']);

        // Balance: only Store A documents; null (unavailable) ≠ "0.0000" (visible zero).
        $balance = $this->tool(SalesAssistantTools::class, 'getCustomerBalanceSummary', ['customer_id' => (int) $customer->getKey()])['data'];
        $this->assertSame([null, null, '0.0000'], [$balance['paid_total'], $balance['outstanding'], $balance['invoiced_total']]);
        $this->assertSame(\App\Support\Decimal::normalize((string) $orderA->total_incl_tax), $balance['sales_total'], 'Store B order excluded.');
        $this->assertArrayHasKey('null_semantics', $balance['definitions']);
    }

    public function test_deactivated_store_work_and_attention_are_not_reachable(): void
    {
        [$organization, $storeA, $storeB, $owner] = $this->fixture();
        $seller = $this->member($organization, [$storeA, $storeB], self::SELLER);
        $this->followUp($organization, $storeB, $seller, ['due_date' => '2026-11-10']);
        $item = $this->attention($organization, $this->order($owner, $organization, $storeB), null);
        DB::table('stores')->where('id', $storeB->getKey())->update(['status' => 'inactive']);

        $this->connect($seller, $organization);
        $this->assertSame(0, $this->tool(WorkTools::class, 'getMyWork')['data']['counts']['today']);
        $this->assertSame([], $this->tool(AttentionTools::class, 'getAttention')['data']['items']);
        $this->assertSame('MCP_ATTENTION_NOT_FOUND', $this->tool(AttentionTools::class, 'explainAttention', ['attention_item_id' => (int) $item->getKey()])['error']['code']);
    }

    public function test_list_tools_have_bounded_query_counts(): void
    {
        [$organization, $storeA, , $owner] = $this->fixture();
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $customer = $this->createCustomer($organization, 'ACME');
        $seed = function (int $count) use ($organization, $storeA, $owner, $manager, $customer) {
            app()->forgetScopedInstances(); // seed as the web owner, not through a bound MCP connection
            for ($i = 0; $i < $count; $i++) {
                $this->followUp($organization, $storeA, $manager, ['due_date' => '2026-11-09', 'customer_id' => $customer->getKey()]);
                $this->attention($organization, $this->order($owner, $organization, $storeA, $customer), $manager);
            }
        };
        $queries = function (string $class, string $method, array $arguments) use ($manager, $organization): int {
            $this->connect($manager, $organization);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertTrue($this->tool($class, $method, $arguments)['ok']);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $calls = [[WorkTools::class, 'getMyWork', ['bucket' => 'overdue', 'per_page' => 50]], [AttentionTools::class, 'getAttention', ['per_page' => 50]], [WorkTools::class, 'getTeamWork', []]];

        $seed(3);
        $small = array_map(fn (array $call) => $queries(...$call), $calls);
        $seed(12);
        $this->assertSame($small, array_map(fn (array $call) => $queries(...$call), $calls), 'Query counts must not grow with the number of items.');
    }

    public function test_registry_lists_only_tools_allowed_by_scope_and_role(): void
    {
        [$organization, $storeA] = $this->fixture();
        $seller = $this->member($organization, [$storeA], self::SELLER);
        $tools = function (array $scopes) use ($seller, $organization): array {
            app()->forgetScopedInstances();
            $issued = app(McpConnectionCredentials::class)->issue($seller, $organization, 'Agent', $scopes, null);
            $headers = ['Authorization' => 'Bearer '.$issued['secret'], 'Accept' => 'application/json, text/event-stream'];
            $session = $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->headers->get('Mcp-Session-Id');
            $headers += ['Mcp-Session-Id' => $session, 'Mcp-Protocol-Version' => '2025-11-25'];
            $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            $names = array_column($this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass])->json('result.tools'), 'name');
            $this->flushHeaders();

            return $names;
        };

        $this->assertSame(['get_my_work'], $tools(['follow_ups.view']));
        $listed = $tools(self::ALL_SCOPES);
        foreach (['get_my_work', 'create_follow_up', 'update_follow_up', 'transition_follow_up', 'get_attention', 'explain_attention',
            'create_follow_up_from_attention', 'claim_attention', 'snooze_attention', 'dismiss_attention', 'get_business_timeline'] as $name) {
            $this->assertContains($name, $listed);
        }
        // follow_ups.manage_all is granted as a scope but the seller's role lacks it: no team or assignment tools.
        $this->assertNotContains('get_team_work', $listed);
        $this->assertNotContains('assign_attention', $listed);
    }
}
