<?php

namespace App\Http\Controllers\Mcp\OAuth;

use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Models\McpOAuthClient;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpScopes;
use App\Services\Mcp\OAuth\McpOAuthException;
use App\Services\Mcp\OAuth\McpOAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interactive OAuth consent for remote MCP clients, inside the normal
 * authenticated web session (login, email verification, 2FA policy, CSRF).
 *
 * The validated authorization request is kept server-side in the session
 * under a random id; the approve/deny calls never trust re-sent parameters.
 * The organisation is chosen explicitly here by the signed-in user from their
 * own active memberships — never from the client, never from the browser's
 * active tenant.
 */
final class McpOAuthAuthorizationController extends Controller
{
    private const SESSION_KEY = 'mcp_oauth.requests';

    public function __construct(
        private readonly McpOAuthServer $server,
        private readonly McpConnectionCredentials $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): Response
    {
        abort_unless(config('mcp.oauth.enabled', true), 404);
        $query = $request->query();

        try {
            $trusted = $this->server->trustedRedirect($query);
        } catch (McpOAuthException $exception) {
            // Untrusted client or redirect URI: show the error, never redirect.
            return Inertia::render('Settings/AiMcpAuthorize', ['error' => $exception->description])->toResponse($request);
        }

        try {
            $validated = $this->server->validateAuthorizationRequest($query);
        } catch (McpOAuthException $exception) {
            return $this->away($request, $this->server->redirectUrl($trusted['redirect_uri'], [
                'error' => $exception->error, 'error_description' => $exception->description, 'state' => $trusted['state'],
            ]));
        }

        $client = $trusted['client'];
        $requestId = Str::random(40);
        $pending = collect($request->session()->get(self::SESSION_KEY, []))
            ->filter(fn ($entry) => is_array($entry) && ($entry['expires_at'] ?? 0) > time())
            ->take(-9)->all();
        $pending[$requestId] = [
            'user_id' => $request->user()->getKey(),
            'client_id' => $client->getKey(),
            'redirect_uri' => $trusted['redirect_uri'],
            'state' => $trusted['state'],
            'code_challenge' => $validated['code_challenge'],
            'resource' => $validated['resource'],
            'requested_scopes' => $validated['requested_scopes'],
            'expires_at' => time() + (int) config('mcp.oauth.authorization_request_ttl_seconds', 600),
        ];
        $request->session()->put(self::SESSION_KEY, $pending);

        return Inertia::render('Settings/AiMcpAuthorize', [
            'error' => null,
            'requestId' => $requestId,
            'client' => [
                'id' => $client->getKey(),
                'name' => $client->name,
                'client_uri' => $client->client_uri,
                'redirect' => $this->redirectLabel($trusted['redirect_uri']),
            ],
            'requestedScopes' => $validated['requested_scopes'],
            // Pre-selection when the client requested no explicit scope.
            'defaultScopes' => config('mcp.presets.read_only', []),
            'organizations' => $this->organizations($request->user(), $client, $validated['requested_scopes']),
        ])->toResponse($request);
    }

    public function approve(Request $request, string $requestId): JsonResponse
    {
        $pending = $this->pending($request, $requestId);
        $client = McpOAuthClient::query()->whereKey($pending['client_id'])->first();
        abort_unless($client && $client->isUsable(), 410, 'The client is no longer registered.');

        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'connection_id' => ['nullable', 'string', 'size:26'],
            'connection_name' => ['nullable', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1', 'max:'.McpScopes::MAX_PER_CONNECTION],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(config('mcp.scopes', [])))],
        ]);

        $user = $request->user();
        $membership = OrganizationMembership::query()->with('organization')
            ->where('user_id', $user->getKey())->where('organization_id', $data['organization_id'])->where('status', 'active')->first();
        $organization = $membership?->organization;
        abort_unless($organization && $organization->status === 'active', 403);
        abort_unless($organization->allowsMcpConnections(), 409, 'AI/MCP connections are disabled for this organization.');

        // Consent can only grant what was requested AND what the user holds now.
        $grantable = $this->grantableScopes($user, $organization->getKey(), $pending['requested_scopes']);
        abort_unless(array_diff($data['scopes'], $grantable) === [], 403);

        [$connection, $reused] = DB::transaction(function () use ($data, $user, $organization, $client) {
            if (! empty($data['connection_id'])) {
                $connection = McpConnection::query()->with(['organization', 'scopeRecords'])
                    ->whereKey($data['connection_id'])
                    ->where('user_id', $user->getKey())->where('organization_id', $organization->getKey())
                    ->where('auth_mode', McpConnection::AUTH_OAUTH)->where('oauth_client_id', $client->getKey())
                    ->lockForUpdate()->first();
                abort_unless($connection && $connection->isUsable(), 422, 'The selected connection cannot be used.');
                $old = ['name' => $connection->name, 'scopes' => $connection->scopeKeys()];
                if (filled($data['connection_name'] ?? null)) $connection->name = trim($data['connection_name']);
                $connection->save();
                $this->credentials->replaceScopes($connection, $data['scopes']);
                $this->audit->record('mcp.connection_updated', $user, $organization, auditable: $connection, oldValues: $old, newValues: [
                    'name' => $connection->name, 'scopes' => $data['scopes'], 'via' => 'oauth_consent',
                ]);

                return [$connection, true];
            }

            $name = filled($data['connection_name'] ?? null) ? trim($data['connection_name']) : Str::limit($client->name, 100, '');
            // The opaque secret is discarded: an OAuth connection is reachable through OAuth tokens only.
            $issued = $this->credentials->issue($user, $organization, $name, $data['scopes'], null, $client);

            return [$issued['connection'], false];
        });

        $code = $this->server->issueCode($client, $connection, $pending['redirect_uri'], $pending['code_challenge'], $pending['resource'], $data['scopes']);
        $this->forget($request, $requestId);
        $this->audit->record('mcp.oauth_authorized', $user, $organization, auditable: $connection, newValues: [
            'client_id' => $client->getKey(), 'client_name' => $client->name, 'connection_id' => $connection->getKey(),
            'scopes' => $data['scopes'], 'reused_connection' => $reused,
        ]);

        return response()->json(['redirect_to' => $this->server->redirectUrl($pending['redirect_uri'], ['code' => $code, 'state' => $pending['state']])]);
    }

    public function deny(Request $request, string $requestId): JsonResponse
    {
        $pending = $this->pending($request, $requestId);
        $this->forget($request, $requestId);
        $this->audit->record('mcp.oauth_denied', $request->user(), newValues: ['client_id' => $pending['client_id']]);

        return response()->json(['redirect_to' => $this->server->redirectUrl($pending['redirect_uri'], [
            'error' => 'access_denied', 'error_description' => 'The user denied the authorization request.', 'state' => $pending['state'],
        ])]);
    }

    /** @return array<string, mixed> */
    private function pending(Request $request, string $requestId): array
    {
        $pending = $request->session()->get(self::SESSION_KEY.'.'.$requestId);
        abort_unless(is_array($pending) && ($pending['expires_at'] ?? 0) > time()
            && (int) $pending['user_id'] === (int) $request->user()->getKey(), 410, 'The authorization request expired. Start again from the client.');

        return $pending;
    }

    private function forget(Request $request, string $requestId): void
    {
        $request->session()->forget(self::SESSION_KEY.'.'.$requestId);
    }

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    private function grantableScopes(User $user, int $organizationId, array $requested): array
    {
        $permissions = $user->permissionKeysFor($organizationId);

        return array_values(array_filter(
            array_keys(McpScopes::definitions()),
            fn (string $scope) => McpScopes::grantedBy($scope, $permissions)
                && ($requested === [] || in_array($scope, $requested, true)),
        ));
    }

    /** @param list<string> $requested @return list<array<string, mixed>> */
    private function organizations(User $user, McpOAuthClient $client, array $requested): array
    {
        return OrganizationMembership::query()->with('organization')
            ->where('user_id', $user->getKey())->where('status', 'active')->get()
            ->filter(fn (OrganizationMembership $membership) => $membership->organization?->status === 'active')
            ->sortBy(fn (OrganizationMembership $membership) => $membership->organization->name)
            ->map(function (OrganizationMembership $membership) use ($user, $client, $requested) {
                $organization = $membership->organization;
                $grantable = $this->grantableScopes($user, $organization->getKey(), $requested);

                return [
                    'id' => $organization->getKey(),
                    'name' => $organization->name,
                    'mcp_enabled' => $organization->allowsMcpConnections(),
                    'scopes' => collect(config('mcp.scopes', []))->map(fn ($value, $key) => [
                        'key' => $key,
                        'label' => $value['label'],
                        'grantable' => in_array($key, $grantable, true),
                        'requested' => $requested === [] || in_array($key, $requested, true),
                    ])->values()->all(),
                    'connections' => McpConnection::query()->with('scopeRecords')
                        ->where('user_id', $user->getKey())->where('organization_id', $organization->getKey())
                        ->where('auth_mode', McpConnection::AUTH_OAUTH)->where('oauth_client_id', $client->getKey())
                        ->latest()->get()
                        ->filter(fn (McpConnection $connection) => $connection->isUsable())
                        ->map(fn (McpConnection $connection) => ['id' => $connection->getKey(), 'name' => $connection->name, 'scopes' => $connection->scopeKeys()])
                        ->values()->all(),
                ];
            })->values()->all();
    }

    private function redirectLabel(string $uri): string
    {
        $parts = parse_url($uri);
        $host = $parts['host'] ?? '';

        return ($parts['scheme'] ?? '').'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function away(Request $request, string $url): Response
    {
        return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
    }
}
