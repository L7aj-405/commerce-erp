<?php

namespace Tests\Feature\Mcp;

use App\Models\McpConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpScopes;
use App\Services\Mcp\OAuth\McpOAuthServer;
use Illuminate\Support\Str;
use Tests\Support\PlatformTestCase;

/**
 * The per-connection scope limit (McpScopes::MAX_PER_CONNECTION) is one shared
 * size guard for connection create/update and OAuth consent; it never widens
 * what a scope grants or who may grant it.
 */
class McpConnectionScopeLimitTest extends PlatformTestCase
{
    public function test_the_limit_is_one_hundred_and_covers_the_whole_catalogue(): void
    {
        $this->assertSame(100, McpScopes::MAX_PER_CONNECTION);
        $this->assertGreaterThan(50, count($this->allScopes()), 'The catalogue outgrew the old 50-scope limit.');
        $this->assertLessThanOrEqual(McpScopes::MAX_PER_CONNECTION, count($this->allScopes()));
    }

    public function test_fifty_scopes_are_still_accepted(): void
    {
        [$owner] = $this->enabledOrganization();

        $this->withFreshAuthentication()->actingAs($owner)
            ->postJson(route('settings.ai-mcp.store'), ['name' => 'Agent', 'scopes' => array_slice($this->allScopes(), 0, 50)])
            ->assertCreated();
        $this->assertCount(50, McpConnection::query()->sole()->scopeKeys());
    }

    public function test_a_connection_holding_every_scope_including_the_purchasing_ones_can_be_saved(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        // The live ChatGPT connection: (almost) every scope, then the two purchasing scopes added.
        $current = array_values(array_diff($this->allScopes(), ['purchase_orders.create', 'procurement_receipts.create']));
        $connection = app(McpConnectionCredentials::class)->issue($owner, $organization, 'ChatGPT ERP', $current, null)['connection'];

        $this->withFreshAuthentication()->actingAs($owner)
            ->putJson(route('settings.ai-mcp.update', $connection), ['name' => 'ChatGPT ERP', 'scopes' => $this->allScopes()])
            ->assertOk();

        $saved = $connection->fresh()->scopeKeys();
        $this->assertCount(count($this->allScopes()), $saved);
        $this->assertContains('purchase_orders.create', $saved);
        $this->assertContains('procurement_receipts.create', $saved);
    }

    public function test_more_than_the_limit_is_rejected(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $connection = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Agent', ['products.view'], null)['connection'];
        $tooMany = array_map(fn (int $i) => 'scope.'.$i, range(1, McpScopes::MAX_PER_CONNECTION + 1));

        $this->withFreshAuthentication()->actingAs($owner)
            ->postJson(route('settings.ai-mcp.store'), ['name' => 'Agent', 'scopes' => $tooMany])
            ->assertStatus(422)->assertJsonPath('errors.scopes.0', 'The scopes field must not have more than 100 items.');
        $this->withFreshAuthentication()->actingAs($owner)
            ->putJson(route('settings.ai-mcp.update', $connection), ['name' => 'Agent', 'scopes' => $tooMany])
            ->assertStatus(422)->assertJsonValidationErrors('scopes');
        $this->assertSame(['products.view'], $connection->fresh()->scopeKeys());
    }

    public function test_unknown_scopes_are_still_rejected(): void
    {
        [$owner] = $this->enabledOrganization();

        $this->withFreshAuthentication()->actingAs($owner)
            ->postJson(route('settings.ai-mcp.store'), ['name' => 'Agent', 'scopes' => ['products.view', 'everything.admin']])
            ->assertStatus(422)->assertJsonValidationErrors('scopes.1');
        $this->assertSame(0, McpConnection::query()->count());
    }

    public function test_adding_scopes_still_requires_recent_authentication(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $current = array_values(array_diff($this->allScopes(), ['purchase_orders.create', 'procurement_receipts.create']));
        $connection = app(McpConnectionCredentials::class)->issue($owner, $organization, 'ChatGPT ERP', $current, null)['connection'];

        $this->withSession(['auth.fresh.account_confirmed_at' => 0, 'auth.fresh.two_factor_confirmed_at' => 0])->actingAs($owner)
            ->putJson(route('settings.ai-mcp.update', $connection), ['name' => 'ChatGPT ERP', 'scopes' => $this->allScopes()])
            ->assertStatus(409)->assertJsonPath('code', 'FRESH_AUTH_REQUIRED');
        $this->assertNotContains('purchase_orders.create', $connection->fresh()->scopeKeys());

        // Removing scopes keeps working without it, as before.
        $this->withSession(['auth.fresh.account_confirmed_at' => 0, 'auth.fresh.two_factor_confirmed_at' => 0])->actingAs($owner)
            ->putJson(route('settings.ai-mcp.update', $connection), ['name' => 'ChatGPT ERP', 'scopes' => array_slice($current, 0, 10)])
            ->assertOk();
    }

    public function test_oauth_consent_uses_the_same_limit(): void
    {
        [$owner, $organization] = $this->enabledOrganization();
        $client = app(McpOAuthServer::class)->registerClient(['redirect_uris' => ['https://chat.example.com/callback'], 'token_endpoint_auth_method' => 'none', 'client_name' => 'ChatGPT']);

        // Every scope (more than the old 50): accepted.
        $requestId = Str::random(40);
        $this->withFreshAuthentication()->withSession(['mcp_oauth.requests' => [$requestId => $this->pending($owner, $client['client_id'], $this->allScopes())]])
            ->actingAs($owner)->postJson(route('mcp.oauth.approve', $requestId), ['organization_id' => $organization->id, 'scopes' => $this->allScopes()])
            ->assertOk();

        // Above the limit: rejected by the same rule.
        $tooMany = array_map(fn (int $i) => 'scope.'.$i, range(1, McpScopes::MAX_PER_CONNECTION + 1));
        $requestId = Str::random(40);
        $this->withFreshAuthentication()->withSession(['mcp_oauth.requests' => [$requestId => $this->pending($owner, $client['client_id'], $this->allScopes())]])
            ->actingAs($owner)->postJson(route('mcp.oauth.approve', $requestId), ['organization_id' => $organization->id, 'scopes' => $tooMany])
            ->assertStatus(422)->assertJsonPath('errors.scopes.0', 'The scopes field must not have more than 100 items.');
    }

    /** @return list<string> */
    private function allScopes(): array
    {
        return array_keys(McpScopes::definitions());
    }

    /** @param list<string> $scopes @return array<string, mixed> */
    private function pending(User $owner, string $clientId, array $scopes): array
    {
        return [
            'user_id' => $owner->id, 'client_id' => $clientId, 'redirect_uri' => 'https://chat.example.com/callback', 'state' => 'xyz',
            'code_challenge' => str_repeat('a', 43), 'resource' => url('/mcp'), 'requested_scopes' => $scopes, 'expires_at' => time() + 600,
        ];
    }

    /** @return array{User, Organization} */
    private function enabledOrganization(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $settings = $organization->settings ?? [];
        data_set($settings, 'allow_mcp_connections', true);
        $organization->settings = $settings;
        $organization->save();

        return [$owner, $organization->refresh()];
    }
}
