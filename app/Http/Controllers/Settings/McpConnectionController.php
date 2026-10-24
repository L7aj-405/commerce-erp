<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Security\FreshAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class McpConnectionController extends Controller
{
    public function index(Request $request, ActiveTenantContext $tenant): Response
    {
        $organization = $tenant->organizationOrFail();
        $canAdminister = $this->isOrganizationAdministrator($request, $organization);
        $connections = McpConnection::query()
            ->with(['user:id,name,email', 'scopeRecords', 'oauthClient:id,name'])
            ->where('organization_id', $organization->getKey())
            ->when(! $canAdminister, fn ($query) => $query->where('user_id', $request->user()->getKey()))
            ->latest()->get()
            ->map(fn (McpConnection $connection) => $this->present($connection, $request));

        return Inertia::render('Settings/AiMcp', [
            'organization' => ['id' => $organization->getKey(), 'name' => $organization->name],
            'enabled' => $organization->allowsMcpConnections(),
            'canAdminister' => $canAdminister,
            'connections' => $connections,
            'availableScopes' => collect(config('mcp.scopes', []))->map(fn ($value, $key) => [
                'key' => $key,
                'label' => $value['label'],
                'available' => $request->user()->hasPermission($organization, $value['permission']),
                'permission' => $value['permission'],
            ])->values(),
            'presets' => config('mcp.presets', []),
        ]);
    }

    public function policy(Request $request, ActiveTenantContext $tenant, AuditLogger $audit): JsonResponse
    {
        $organization = $tenant->organizationOrFail();
        abort_unless($this->isOrganizationAdministrator($request, $organization), 403);
        $validated = $request->validate(['enabled' => ['required', 'boolean'], 'organization_id' => ['prohibited']]);
        $settings = $organization->settings ?? [];
        $old = (bool) data_get($settings, 'allow_mcp_connections', false);
        data_set($settings, 'allow_mcp_connections', (bool) $validated['enabled']);
        $organization->settings = $settings;
        $organization->save();
        $audit->record('mcp.organization_policy_changed', $request->user(), $organization, auditable: $organization, oldValues: ['enabled' => $old], newValues: ['enabled' => (bool) $validated['enabled']]);

        return response()->json(['data' => ['enabled' => $organization->allowsMcpConnections()]]);
    }

    public function store(Request $request, ActiveTenantContext $tenant, McpConnectionCredentials $credentials): JsonResponse
    {
        $organization = $tenant->organizationOrFail();
        abort_unless($organization->allowsMcpConnections(), 409, 'AI/MCP connections are disabled for this organization.');
        $validated = $this->validateConnection($request);
        $this->assertScopeCeiling($request, $organization, $validated['scopes']);
        $issued = $credentials->issue($request->user(), $organization, $validated['name'], $validated['scopes'], $validated['expires_at'] ?? null);

        return response()->json(['data' => [
            'connection' => $this->present($issued['connection'], $request),
            'secret' => $issued['secret'],
            'secret_display_once' => true,
        ]], 201);
    }

    public function update(string $connection, Request $request, ActiveTenantContext $tenant, McpConnectionCredentials $credentials, FreshAuthentication $fresh, AuditLogger $audit): JsonResponse
    {
        $organization = $tenant->organizationOrFail();
        $model = $this->ownedConnection($connection, $organization, $request);
        $validated = $this->validateConnection($request);
        $this->assertScopeCeiling($request, $organization, $validated['scopes']);
        $old = ['name' => $model->name, 'scopes' => $model->scopeKeys(), 'expires_at' => $model->expires_at?->toIso8601String()];
        $added = array_diff($validated['scopes'], $model->scopeKeys());
        if ($added !== []) $fresh->ensure($request, FreshAuthentication::LEVEL_TWO_FACTOR);

        $model->name = $validated['name'];
        $model->expires_at = $validated['expires_at'] ?? null;
        $model->save();
        $credentials->replaceScopes($model, $validated['scopes']);
        $audit->record('mcp.connection_updated', $request->user(), $organization, auditable: $model, oldValues: $old, newValues: [
            'name' => $model->name, 'scopes' => $validated['scopes'], 'expires_at' => $model->expires_at?->toIso8601String(),
        ]);

        return response()->json(['data' => ['connection' => $this->present($model->load('scopeRecords'), $request)]]);
    }

    public function rotate(string $connection, Request $request, ActiveTenantContext $tenant, McpConnectionCredentials $credentials): JsonResponse
    {
        $model = $this->ownedConnection($connection, $tenant->organizationOrFail(), $request);
        abort_unless($model->isUsable(), 409, 'Only an active connection can be rotated.');
        // An OAuth connection must never gain a static secret that bypasses OAuth.
        abort_if($model->isOAuth(), 409, 'OAuth connections have no secret to rotate.');
        $issued = $credentials->rotate($model, $request->user());

        return response()->json(['data' => ['connection' => $this->present($issued['connection'], $request), 'secret' => $issued['secret'], 'secret_display_once' => true]]);
    }

    public function destroy(string $connection, Request $request, ActiveTenantContext $tenant, McpConnectionCredentials $credentials): JsonResponse
    {
        $organization = $tenant->organizationOrFail();
        $model = McpConnection::query()->with(['organization', 'scopeRecords'])->where('organization_id', $organization->getKey())->findOrFail($connection);
        abort_unless($model->user_id === $request->user()->getKey() || $this->isOrganizationAdministrator($request, $organization), 403);
        $credentials->revoke($model, $request->user());

        return response()->json(['data' => ['connection' => $this->present($model, $request)]]);
    }

    /** @return array{name:string,scopes:list<string>,expires_at?:string|null} */
    private function validateConnection(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1', 'max:50'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(config('mcp.scopes', [])))],
            'expires_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays((int) config('mcp.max_expiration_days', 365))->toIso8601String()],
            'organization_id' => ['prohibited'],
            'token' => ['prohibited'],
        ]);
    }

    /** @param list<string> $scopes */
    private function assertScopeCeiling(Request $request, Organization $organization, array $scopes): void
    {
        foreach ($scopes as $scope) {
            $permission = config('mcp.scopes', [])[$scope]['permission'] ?? null;
            abort_unless(is_string($permission) && $request->user()->hasPermission($organization, $permission), 403);
        }
    }

    private function ownedConnection(string $id, Organization $organization, Request $request): McpConnection
    {
        return McpConnection::query()->with(['organization', 'scopeRecords'])
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $request->user()->getKey())
            ->findOrFail($id);
    }

    private function isOrganizationAdministrator(Request $request, Organization $organization): bool
    {
        if ((int) $organization->owner_id === (int) $request->user()->getKey()) return true;

        return OrganizationMembership::query()->where('organization_id', $organization->getKey())
            ->where('user_id', $request->user()->getKey())->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['owner', 'admin']))->exists();
    }

    /** @return array<string,mixed> */
    private function present(McpConnection $connection, Request $request): array
    {
        return [
            'id' => $connection->getKey(), 'name' => $connection->name, 'status' => $connection->status,
            'scopes' => $connection->scopeKeys(), 'expires_at' => $connection->expires_at?->toIso8601String(),
            'last_used_at' => $connection->last_used_at?->toIso8601String(), 'created_at' => $connection->created_at?->toIso8601String(),
            'owner' => $connection->relationLoaded('user') ? $connection->user?->only(['id', 'name', 'email']) : null,
            'is_own' => (int) $connection->user_id === (int) $request->user()->getKey(),
            'auth_mode' => $connection->auth_mode ?? McpConnection::AUTH_TOKEN,
            'oauth_client' => $connection->isOAuth() && $connection->relationLoaded('oauthClient') ? $connection->oauthClient?->name : null,
        ];
    }
}
