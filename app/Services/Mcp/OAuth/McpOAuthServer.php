<?php

namespace App\Services\Mcp\OAuth;

use App\Models\McpConnection;
use App\Models\McpOAuthAuthorizationCode;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthToken;
use App\Services\AuditLogger;
use App\Services\Mcp\McpConnectionGate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OAuth 2.1 authorization server for remote MCP clients: dynamic client
 * registration (RFC 7591), authorization code + mandatory PKCE S256, rotating
 * refresh tokens with reuse detection, revocation (RFC 7009) and RFC 8707
 * resource binding. Every grant is bound to one McpConnection, so the MCP
 * endpoint authorizes exactly as for opaque connection secrets.
 *
 * Only SHA-256 hashes of codes, tokens and client secrets are stored.
 */
final class McpOAuthServer
{
    private const BLOCKED_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss'];

    public function __construct(
        private readonly McpOAuthMetadata $metadata,
        private readonly McpConnectionGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /* ------------------------------------------------------------------
     |  Dynamic client registration (RFC 7591)
     * ---------------------------------------------------------------- */

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function registerClient(array $input): array
    {
        $redirectUris = $input['redirect_uris'] ?? null;
        if (! is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > (int) config('mcp.oauth.max_redirect_uris', 10)) {
            throw new McpOAuthException('invalid_redirect_uri', 'One to ten redirect_uris are required.');
        }
        foreach ($redirectUris as $uri) {
            if (! is_string($uri) || ! $this->validRedirectUri($uri)) {
                throw new McpOAuthException('invalid_redirect_uri', 'A redirect URI is not allowed. Use https, a loopback http URI, or a private-use scheme, without fragments or wildcards.');
            }
        }

        $method = $input['token_endpoint_auth_method'] ?? McpOAuthClient::AUTH_NONE;
        if (! in_array($method, [McpOAuthClient::AUTH_NONE, McpOAuthClient::AUTH_SECRET_POST, McpOAuthClient::AUTH_SECRET_BASIC], true)) {
            throw new McpOAuthException('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
        }
        foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $field => $allowed) {
            if (isset($input[$field]) && (! is_array($input[$field]) || array_diff($input[$field], $allowed) !== [])) {
                throw new McpOAuthException('invalid_client_metadata', "Unsupported {$field}.");
            }
        }
        $name = is_string($input['client_name'] ?? null) && trim($input['client_name']) !== ''
            ? Str::limit(trim(strip_tags($input['client_name'])), 150, '')
            : 'MCP client';
        $clientUri = is_string($input['client_uri'] ?? null) && str_starts_with($input['client_uri'], 'https://') && mb_strlen($input['client_uri']) <= 2000
            ? $input['client_uri'] : null;

        $client = new McpOAuthClient;
        $client->id = 'mcpc_'.Str::lower((string) Str::ulid());
        $client->name = $name;
        $client->redirect_uris = array_values(array_unique($redirectUris));
        $client->token_endpoint_auth_method = $method;
        $client->client_uri = $clientUri;
        $client->software_id = is_string($input['software_id'] ?? null) ? Str::limit($input['software_id'], 255, '') : null;
        $client->software_version = is_string($input['software_version'] ?? null) ? Str::limit($input['software_version'], 100, '') : null;
        $secret = null;
        if ($method !== McpOAuthClient::AUTH_NONE) {
            $secret = 'mcpcs_'.bin2hex(random_bytes(32));
            $client->secret_hash = hash('sha256', $secret);
        }
        $client->save();

        $this->audit->record('mcp.oauth_client_registered', newValues: [
            'client_id' => $client->id, 'name' => $client->name, 'auth_method' => $method,
            'redirect_hosts' => array_values(array_unique(array_map(fn ($uri) => parse_url($uri, PHP_URL_SCHEME).'://'.parse_url($uri, PHP_URL_HOST), $client->redirect_uris))),
        ]);

        return array_filter([
            'client_id' => $client->id,
            'client_secret' => $secret,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_secret_expires_at' => $secret !== null ? 0 : null,
            'client_name' => $client->name,
            'client_uri' => $client->client_uri,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
        ], fn ($value) => $value !== null);
    }

    public function validRedirectUri(string $uri): bool
    {
        if (mb_strlen($uri) > 2000 || str_contains($uri, '*') || str_contains($uri, '#') || preg_match('/\s/', $uri)) return false;
        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === '' || in_array($scheme, self::BLOCKED_SCHEMES, true) || isset($parts['user']) || isset($parts['pass'])) return false;

        return match (true) {
            $scheme === 'https' => isset($parts['host']) && $parts['host'] !== '',
            // RFC 8252 §7.3 loopback redirects for native/local clients.
            $scheme === 'http' => in_array(strtolower((string) ($parts['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]'], true),
            // RFC 8252 §7.1 private-use schemes (cursor://, vscode://, com.example.app:/…).
            default => (bool) preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme),
        };
    }

    /* ------------------------------------------------------------------
     |  Authorization request
     * ---------------------------------------------------------------- */

    /**
     * Validates the client and redirect URI first: only after both are
     * trusted may an error be redirected back to the client.
     *
     * @param array<string, mixed> $query
     * @return array{client: McpOAuthClient, redirect_uri: string, state: ?string}
     */
    public function trustedRedirect(array $query): array
    {
        $client = is_string($query['client_id'] ?? null)
            ? McpOAuthClient::query()->whereKey($query['client_id'])->first()
            : null;
        if (! $client || ! $client->isUsable()) {
            throw new McpOAuthException('invalid_client', 'The client is unknown or has been revoked.');
        }

        $redirectUri = $query['redirect_uri'] ?? null;
        if ($redirectUri === null && count($client->redirect_uris) === 1) {
            $redirectUri = $client->redirect_uris[0];
        }
        // Exact string comparison against a registered URI — no prefix, no wildcard.
        if (! is_string($redirectUri) || ! in_array($redirectUri, $client->redirect_uris, true)) {
            throw new McpOAuthException('invalid_request', 'The redirect_uri does not match a registered redirect URI.');
        }

        return ['client' => $client, 'redirect_uri' => $redirectUri, 'state' => is_string($query['state'] ?? null) ? Str::limit($query['state'], 1000, '') : null];
    }

    /**
     * Remaining request checks; failures here are redirected to the client.
     *
     * @param array<string, mixed> $query
     * @return array{response_type: string, code_challenge: string, resource: string, requested_scopes: list<string>}
     */
    public function validateAuthorizationRequest(array $query): array
    {
        if (($query['response_type'] ?? null) !== 'code') {
            throw new McpOAuthException('unsupported_response_type', 'Only the authorization code flow is supported.');
        }
        $challenge = $query['code_challenge'] ?? null;
        if (($query['code_challenge_method'] ?? null) !== 'S256' || ! is_string($challenge) || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            throw new McpOAuthException('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        $resource = $query['resource'] ?? $this->metadata->resource();
        if (! is_string($resource) || ! $this->metadata->resourceMatches($resource)) {
            throw new McpOAuthException('invalid_target', 'The requested resource is not served by this authorization server.');
        }

        $requested = [];
        if (is_string($query['scope'] ?? null) && trim($query['scope']) !== '') {
            $known = $this->metadata->scopes();
            $requested = array_values(array_unique(array_intersect(preg_split('/\s+/', trim($query['scope'])) ?: [], $known)));
            if ($requested === []) {
                throw new McpOAuthException('invalid_scope', 'None of the requested scopes are supported.');
            }
        }

        return ['response_type' => 'code', 'code_challenge' => $challenge, 'resource' => $this->metadata->resource(), 'requested_scopes' => $requested];
    }

    /** @param list<string> $scopes */
    public function issueCode(McpOAuthClient $client, McpConnection $connection, string $redirectUri, string $codeChallenge, string $resource, array $scopes): string
    {
        $code = $this->secret();
        $record = new McpOAuthAuthorizationCode;
        $record->code_hash = hash('sha256', $code);
        $record->client_id = $client->getKey();
        $record->mcp_connection_id = $connection->getKey();
        $record->redirect_uri = $redirectUri;
        $record->code_challenge = $codeChallenge;
        $record->resource = $resource;
        $record->scopes = $scopes;
        $record->expires_at = now()->addSeconds((int) config('mcp.oauth.authorization_code_ttl_seconds', 120));
        $record->save();

        return $code;
    }

    /** RFC 9207-style redirect back to the client. @param array<string, string|null> $params */
    public function redirectUrl(string $redirectUri, array $params): string
    {
        $params = array_filter([...$params, 'iss' => $this->metadata->issuer()], fn ($value) => $value !== null);

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /* ------------------------------------------------------------------
     |  Token endpoint
     * ---------------------------------------------------------------- */

    /** Client authentication per its registered method (RFC 6749 §2.3). */
    public function authenticateClient(Request $request): McpOAuthClient
    {
        $id = $request->input('client_id');
        $secret = $request->input('client_secret');
        $basic = false;
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded === false || ! str_contains($decoded, ':')) throw $this->invalidClient();
            [$basicId, $basicSecret] = explode(':', $decoded, 2);
            [$id, $secret, $basic] = [urldecode($basicId), urldecode($basicSecret), true];
        }

        $client = is_string($id) ? McpOAuthClient::query()->whereKey($id)->first() : null;
        if (! $client || ! $client->isUsable()) throw $this->invalidClient();

        $valid = match ($client->token_endpoint_auth_method) {
            McpOAuthClient::AUTH_NONE => ! $basic && ($secret === null || $secret === ''),
            McpOAuthClient::AUTH_SECRET_BASIC => $basic && is_string($secret) && hash_equals((string) $client->secret_hash, hash('sha256', $secret)),
            McpOAuthClient::AUTH_SECRET_POST => ! $basic && is_string($secret) && hash_equals((string) $client->secret_hash, hash('sha256', $secret)),
            default => false,
        };
        if (! $valid) throw $this->invalidClient();

        return $client;
    }

    /** @return array<string, mixed> */
    public function exchangeAuthorizationCode(McpOAuthClient $client, Request $request): array
    {
        $code = $request->input('code');
        $verifier = $request->input('code_verifier');
        if (! is_string($code) || $code === '' || ! is_string($verifier) || ! preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            throw new McpOAuthException('invalid_request', 'code and a valid code_verifier are required.');
        }

        $record = McpOAuthAuthorizationCode::query()->where('code_hash', hash('sha256', $code))->first();
        if (! $record) throw $this->invalidGrant();

        // Burn the code atomically and durably BEFORE any other check, so a
        // failed attempt (wrong verifier, wrong client) can never be retried.
        $claimed = McpOAuthAuthorizationCode::query()->whereKey($record->getKey())->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            // Code replay: revoke everything issued from it (OAuth 2.1 §4.1.3).
            McpOAuthToken::query()->where('authorization_code_id', $record->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
            throw $this->invalidGrant();
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $resource = $request->input('resource', $record->resource);
        $redirectUri = $request->input('redirect_uri') ?? (count($client->redirect_uris) === 1 ? $client->redirect_uris[0] : null);
        if ($record->client_id !== $client->getKey()
            || $record->expires_at->isPast()
            || $redirectUri !== $record->redirect_uri
            || ! hash_equals($record->code_challenge, $challenge)
            || ! is_string($resource) || ! $this->metadata->resourceMatches($resource)) {
            throw $this->invalidGrant();
        }

        $connection = $this->usableConnection($record->mcp_connection_id, $client);

        return DB::transaction(fn () => $this->issueTokens($client, $connection, $record->resource, (string) Str::ulid(), $record->getKey()));
    }

    /** @return array<string, mixed> */
    public function refresh(McpOAuthClient $client, Request $request): array
    {
        $refresh = $request->input('refresh_token');
        if (! is_string($refresh) || ! preg_match('/^10xort_[a-f0-9]{64}$/', $refresh)) throw $this->invalidGrant();

        $token = McpOAuthToken::query()->where('refresh_token_hash', hash('sha256', $refresh))->first();
        if (! $token || $token->client_id !== $client->getKey()) throw $this->invalidGrant();

        if ($token->rotated_at !== null || $token->revoked_at !== null) {
            // A rotated refresh token was replayed: treat the family as compromised.
            $this->revokeFamily($token->family_id);
            throw $this->invalidGrant();
        }
        if ($token->refresh_expires_at === null || $token->refresh_expires_at->isPast()) throw $this->invalidGrant();

        $connection = $this->usableConnection($token->mcp_connection_id, $client);
        if (is_string($request->input('scope')) && trim($request->input('scope')) !== ''
            && array_diff(preg_split('/\s+/', trim($request->input('scope'))) ?: [], $connection->scopeKeys()) !== []) {
            throw new McpOAuthException('invalid_scope', 'The refresh request asks for scopes beyond the original grant.');
        }

        // Atomic rotation: of two concurrent uses of one refresh token, only
        // one wins; the loser is treated as a replay.
        $rotated = McpOAuthToken::query()->whereKey($token->getKey())->whereNull('rotated_at')->whereNull('revoked_at')
            ->update(['rotated_at' => now(), 'updated_at' => now()]);
        if ($rotated !== 1) {
            $this->revokeFamily($token->family_id);
            throw $this->invalidGrant();
        }

        return DB::transaction(fn () => $this->issueTokens($client, $connection, $token->resource, $token->family_id, $token->authorization_code_id));
    }

    /** RFC 7009: revokes the whole token family; unknown tokens are silently ignored. */
    public function revoke(McpOAuthClient $client, string $token): void
    {
        $hash = hash('sha256', $token);
        $record = McpOAuthToken::query()
            ->where(fn ($query) => $query->where('access_token_hash', $hash)->orWhere('refresh_token_hash', $hash))
            ->first();
        if ($record && $record->client_id === $client->getKey()) {
            $this->revokeFamily($record->family_id);
        }
    }

    public function revokeFamily(string $familyId): void
    {
        McpOAuthToken::query()->where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /* ------------------------------------------------------------------
     |  Resource server side
     * ---------------------------------------------------------------- */

    /** Live, unexpired access token for this resource, or null. Connection checks are the caller's. */
    public function resolveAccessToken(string $raw): ?McpOAuthToken
    {
        if (! preg_match('/^10xoat_[a-f0-9]{64}$/', $raw)) return null;

        $token = McpOAuthToken::query()->with('client')->where('access_token_hash', hash('sha256', $raw))->first();
        if (! $token || $token->revoked_at !== null || $token->access_expires_at->isPast()
            || ! $token->client?->isUsable() || ! $this->metadata->resourceMatches($token->resource)) {
            return null;
        }
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $token;
    }

    /* ---------------------------------------------------------------- */

    private function usableConnection(string $connectionId, McpOAuthClient $client): McpConnection
    {
        $connection = McpConnection::query()->with(['organization', 'scopeRecords'])->find($connectionId);
        if (! $connection || ! $connection->isOAuth() || $connection->oauth_client_id !== $client->getKey() || $this->gate->failure($connection) !== null) {
            throw $this->invalidGrant();
        }

        return $connection;
    }

    /** @return array<string, mixed> */
    private function issueTokens(McpOAuthClient $client, McpConnection $connection, ?string $resource, string $familyId, ?string $codeId): array
    {
        $access = '10xoat_'.bin2hex(random_bytes(32));
        $refresh = '10xort_'.bin2hex(random_bytes(32));
        $accessExpires = now()->addSeconds(max(60, (int) config('mcp.oauth.access_token_ttl_seconds', 900)));
        $refreshExpires = now()->addDays(max(1, (int) config('mcp.oauth.refresh_token_ttl_days', 30)));
        if ($connection->expires_at !== null) {
            $accessExpires = Carbon::instance($accessExpires)->min($connection->expires_at);
            $refreshExpires = Carbon::instance($refreshExpires)->min($connection->expires_at);
        }
        $scopes = $connection->scopeKeys();

        $token = new McpOAuthToken;
        $token->family_id = $familyId;
        $token->client_id = $client->getKey();
        $token->mcp_connection_id = $connection->getKey();
        $token->authorization_code_id = $codeId;
        $token->access_token_hash = hash('sha256', $access);
        $token->access_expires_at = $accessExpires;
        $token->refresh_token_hash = hash('sha256', $refresh);
        $token->refresh_expires_at = $refreshExpires;
        $token->scopes = $scopes;
        $token->resource = $resource;
        $token->save();
        $client->forceFill(['last_used_at' => now()])->saveQuietly();

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => max(1, (int) now()->diffInSeconds($accessExpires, true)),
            'refresh_token' => $refresh,
            'scope' => implode(' ', $scopes),
        ];
    }

    private function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function invalidGrant(): McpOAuthException
    {
        return new McpOAuthException('invalid_grant', 'The authorization grant is invalid, expired, revoked, or was issued to another client.');
    }

    private function invalidClient(): McpOAuthException
    {
        return new McpOAuthException('invalid_client', 'Client authentication failed.', 401);
    }
}
