<?php

namespace Tests\Feature\Mcp;

use App\Models\AuditLog;
use App\Models\McpConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\PlatformTestCase;

class McpConnectionSecurityTest extends PlatformTestCase
{
    public function test_policy_is_off_by_default_and_creation_requires_fresh_l2_and_opt_in(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->withFreshAuthentication()->actingAs($owner)->postJson(route('settings.ai-mcp.store'), $this->payload())
            ->assertStatus(409)->assertSee('AI/MCP connections are disabled');

        $this->withSession(['auth.fresh.account_confirmed_at' => 0, 'auth.fresh.two_factor_confirmed_at' => 0])
            ->actingAs($owner)->putJson(route('settings.ai-mcp.policy'), ['enabled' => true])
            ->assertStatus(409)->assertJsonPath('code', 'FRESH_AUTH_REQUIRED');

        $this->withFreshAuthentication()->actingAs($owner)
            ->putJson(route('settings.ai-mcp.policy'), ['enabled' => true])->assertOk();

        $this->withSession(['auth.fresh.account_confirmed_at' => 0, 'auth.fresh.two_factor_confirmed_at' => 0])
            ->actingAs($owner)->postJson(route('settings.ai-mcp.store'), $this->payload())
            ->assertStatus(409)->assertJsonPath('code', 'FRESH_AUTH_REQUIRED');
    }

    public function test_secret_is_returned_once_but_only_its_hash_is_persisted_and_rotation_invalidates_old_secret(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $response = $this->withFreshAuthentication()->actingAs($owner)
            ->postJson(route('settings.ai-mcp.store'), $this->payload())->assertCreated();
        $secret = $response->json('data.secret');
        $id = $response->json('data.connection.id');
        $connection = McpConnection::findOrFail($id);

        $this->assertStringStartsWith('10xmcp_', $secret);
        $this->assertSame(hash('sha256', $secret), $connection->token_hash);
        $this->assertDatabaseMissing('mcp_connections', ['token_hash' => $secret]);
        $this->assertStringNotContainsString($secret, $connection->toJson());

        $rotated = $this->withFreshAuthentication()->actingAs($owner)
            ->postJson(route('settings.ai-mcp.rotate', $id))->assertOk()->json('data.secret');
        $this->assertNotSame($secret, $rotated);
        $this->mcpRequest($secret)->assertUnauthorized()->assertJsonPath('error.code', 'MCP_INVALID_CREDENTIAL');
        $this->assertNotSame(401, $this->mcpRequest($rotated)->getStatusCode());
    }

    public function test_revoked_expired_disabled_and_removed_membership_credentials_are_rejected_stably(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $issued = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Agent', ['products.view'], null);

        $issued['connection']->status = McpConnection::STATUS_REVOKED;
        $issued['connection']->revoked_at = now();
        $issued['connection']->save();
        $this->mcpRequest($issued['secret'])->assertUnauthorized()->assertJsonPath('error.code', 'MCP_CONNECTION_REVOKED');

        $expired = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Expired', ['products.view'], now()->subMinute()->toIso8601String());
        $this->mcpRequest($expired['secret'])->assertUnauthorized()->assertJsonPath('error.code', 'MCP_CONNECTION_EXPIRED');

        $live = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Live', ['products.view'], null);
        $settings = $organization->settings; data_set($settings, 'allow_mcp_connections', false); $organization->settings = $settings; $organization->save();
        $this->mcpRequest($live['secret'])->assertForbidden()->assertJsonPath('error.code', 'MCP_DISABLED');

        data_set($settings, 'allow_mcp_connections', true); $organization->settings = $settings; $organization->save();
        $organization->memberships()->where('user_id', $owner->id)->update(['status' => 'inactive']);
        $this->mcpRequest($live['secret'])->assertForbidden()->assertJsonPath('error.code', 'MCP_MEMBERSHIP_INACTIVE');
    }

    public function test_connection_tenant_comes_from_credential_not_browser_active_organization_or_input(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'A');
        $organizationB = $this->createOrganization($owner, 'B');
        $this->enable($organizationA);
        $this->enable($organizationB);
        $this->activate($owner, $organizationB);
        $issued = app(McpConnectionCredentials::class)->issue($owner, $organizationA, 'A agent', ['products.view'], null);

        $this->mcpRequest($issued['secret'], ['organization_id' => $organizationB->id]);
        $this->assertSame($organizationA->id, app(McpExecutionContext::class)->organization()->id);
    }

    public function test_effective_authorization_requires_both_scope_and_current_erp_permission(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->enable($organization);
        $member = User::factory()->create();
        $membership = $this->addOrganizationMember($organization, $member, ['products.view']);
        $issued = app(McpConnectionCredentials::class)->issue($member, $organization, 'Viewer', ['products.view'], null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));

        $this->assertTrue(app(McpExecutionContext::class)->allows('products.view'));
        $this->assertFalse(app(McpExecutionContext::class)->allows('products.update'));
        $membership->role->permissions()->detach();
        $this->assertFalse(app(McpExecutionContext::class)->allows('products.view'));
    }

    public function test_http_creation_rejects_scope_above_erp_permission_ceiling(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->enable($organization);
        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, ['products.view']);
        $this->activate($member, $organization);

        $this->withFreshAuthentication()->actingAs($member)->postJson(route('settings.ai-mcp.store'), [
            'name' => 'Too broad', 'scopes' => ['products.update'],
        ])->assertForbidden();
    }

    public function test_owner_sees_and_revokes_organization_connections_but_ordinary_users_only_see_their_own(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $memberA = User::factory()->create(); $this->addOrganizationMember($organization, $memberA, ['products.view']);
        $memberB = User::factory()->create(); $this->addOrganizationMember($organization, $memberB, ['products.view']);
        $a = app(McpConnectionCredentials::class)->issue($memberA, $organization, 'A', ['products.view'], null)['connection'];
        $b = app(McpConnectionCredentials::class)->issue($memberB, $organization, 'B', ['products.view'], null)['connection'];

        $this->activate($owner, $organization);
        $this->actingAs($owner)->get(route('settings.ai-mcp.index'))->assertOk()->assertInertia(fn ($page) => $page->has('connections', 2));
        $this->withFreshAuthentication()->actingAs($owner)->deleteJson(route('settings.ai-mcp.destroy', $a))->assertOk();

        $this->activate($memberB, $organization);
        $this->actingAs($memberB)->get(route('settings.ai-mcp.index'))->assertOk()->assertInertia(fn ($page) => $page->has('connections', 1)->where('connections.0.id', $b->id));
        $this->withFreshAuthentication()->actingAs($memberB)->deleteJson(route('settings.ai-mcp.destroy', $a))->assertForbidden();
    }

    public function test_organization_admin_can_list_and_revoke_all_connections(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $admin = User::factory()->create();
        $adminRole = Role::query()->where('organization_id', $organization->id)->where('slug', 'admin')->firstOrFail();
        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->id; $membership->user_id = $admin->id;
        $membership->role_id = $adminRole->id; $membership->status = 'active'; $membership->save();
        $connection = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Owner agent', ['products.view'], null)['connection'];
        $this->activate($admin, $organization);

        $this->actingAs($admin)->get(route('settings.ai-mcp.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('canAdminister', true)->has('connections', 1));
        $this->withFreshAuthentication()->actingAs($admin)->deleteJson(route('settings.ai-mcp.destroy', $connection))->assertOk();
    }

    public function test_rotate_revoke_and_scope_increase_require_fresh_l2(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $connection = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Agent', ['products.view'], null)['connection'];

        $this->actingAs($owner)->postJson(route('settings.ai-mcp.rotate', $connection))->assertStatus(409)->assertJsonPath('code', 'FRESH_AUTH_REQUIRED');
        $this->actingAs($owner)->deleteJson(route('settings.ai-mcp.destroy', $connection))->assertStatus(409)->assertJsonPath('code', 'FRESH_AUTH_REQUIRED');
        $this->actingAs($owner)->putJson(route('settings.ai-mcp.update', $connection), [
            'name' => 'Agent', 'scopes' => ['products.view', 'products.update'],
        ])->assertStatus(409)->assertJsonPath('code', 'FRESH_AUTH_REQUIRED');
    }

    public function test_mcp_audit_attribution_keeps_erp_actor_and_connection_snapshot(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $issued = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Catalog robot', ['products.view'], null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
        app(AuditLogger::class)->record('mcp.test_event');

        $log = AuditLog::query()->where('event', 'mcp.test_event')->firstOrFail();
        $this->assertSame($owner->id, $log->actor_id);
        $this->assertSame('mcp', $log->actor_channel);
        $this->assertSame($issued['connection']->id, $log->mcp_connection_id);
        $this->assertSame('Catalog robot', $log->mcp_connection_name);
    }

    public function test_mcp_requests_are_rate_limited_per_connection(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $issued = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Agent', ['products.view'], null);
        config(['mcp.rate_limit_per_minute' => 1]);
        RateLimiter::clear('mcp:'.$issued['connection']->id);

        // The request deliberately omits initialize, so the SDK returns a
        // protocol-level 400; it still counts as one authenticated MCP call.
        $this->mcpRequest($issued['secret'])->assertStatus(400);
        $this->mcpRequest($issued['secret'])->assertStatus(429)->assertJsonPath('error.code', 'MCP_RATE_LIMITED');
    }

    public function test_official_streamable_http_registry_is_reachable_and_empty_in_mcp_one(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $issued = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Agent', ['products.view'], null);
        $headers = ['Authorization' => 'Bearer '.$issued['secret'], 'Accept' => 'application/json, text/event-stream'];
        $initialize = $this->withHeaders($headers)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test-client', 'version' => '1.0']],
        ])->assertOk();
        $session = $initialize->headers->get('Mcp-Session-Id');
        $this->assertNotEmpty($session);

        $sessionHeaders = $headers + ['Mcp-Session-Id' => $session, 'Mcp-Protocol-Version' => '2025-11-25'];
        $this->withHeaders($sessionHeaders)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        $this->withHeaders($sessionHeaders)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass])
            ->assertOk()->assertJsonPath('result.tools', []);
    }

    /** @return array{0:User,1:Organization} */
    private function enabledOrganization(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->enable($organization);
        return [$owner, $organization];
    }

    private function enable(Organization $organization): void
    {
        $settings = $organization->settings ?? [];
        data_set($settings, 'allow_mcp_connections', true);
        $organization->settings = $settings;
        $organization->save();
    }

    private function payload(): array { return ['name' => 'Catalog Agent', 'scopes' => ['products.view']]; }

    private function mcpRequest(string $secret, array $params = [])
    {
        return $this->withToken($secret)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => $params,
        ]);
    }
}
