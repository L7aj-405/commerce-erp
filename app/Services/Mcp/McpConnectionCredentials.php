<?php

namespace App\Services\Mcp;

use App\Models\McpConnection;
use App\Models\McpConnectionScope;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class McpConnectionCredentials
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * With an OAuth client the connection is OAuth-only: the generated opaque
     * secret is never shown and is rejected by the MCP endpoint anyway.
     *
     * @param list<string> $scopes @return array{connection:McpConnection,secret:string}
     */
    public function issue(User $user, Organization $organization, string $name, array $scopes, ?string $expiresAt, ?McpOAuthClient $oauthClient = null): array
    {
        return DB::transaction(function () use ($user, $organization, $name, $scopes, $expiresAt, $oauthClient) {
            $connection = new McpConnection;
            $connection->id = (string) Str::ulid();
            $connection->organization_id = $organization->getKey();
            $connection->user_id = $user->getKey();
            $connection->name = $name;
            $connection->status = McpConnection::STATUS_ACTIVE;
            $connection->auth_mode = $oauthClient ? McpConnection::AUTH_OAUTH : McpConnection::AUTH_TOKEN;
            $connection->oauth_client_id = $oauthClient?->getKey();
            $secret = $this->newSecret($connection->id);
            $connection->token_hash = hash('sha256', $secret);
            $connection->expires_at = $expiresAt;
            $connection->save();
            $this->replaceScopes($connection, $scopes);

            $this->auditLogger->record('mcp.connection_created', $user, $organization, auditable: $connection, newValues: [
                'name' => $connection->name,
                'scopes' => $scopes,
                'expires_at' => $connection->expires_at?->toIso8601String(),
                'auth_mode' => $connection->auth_mode,
                'oauth_client_id' => $connection->oauth_client_id,
            ]);

            return ['connection' => $connection->load('scopeRecords'), 'secret' => $secret];
        });
    }

    /** @return array{connection:McpConnection,secret:string} */
    public function rotate(McpConnection $connection, User $actor): array
    {
        return DB::transaction(function () use ($connection, $actor) {
            $secret = $this->newSecret($connection->id);
            $connection->token_hash = hash('sha256', $secret);
            $connection->last_used_at = null;
            $connection->save();
            $this->auditLogger->record('mcp.connection_rotated', $actor, $connection->organization, auditable: $connection, newValues: ['name' => $connection->name]);

            return ['connection' => $connection, 'secret' => $secret];
        });
    }

    public function revoke(McpConnection $connection, User $actor): void
    {
        DB::transaction(function () use ($connection, $actor) {
            if ($connection->status === McpConnection::STATUS_REVOKED) return;
            $connection->status = McpConnection::STATUS_REVOKED;
            $connection->revoked_at = now();
            $connection->save();
            // The endpoint already refuses any token of a revoked connection;
            // revoking the OAuth rows too keeps the token store truthful.
            McpOAuthToken::query()->where('mcp_connection_id', $connection->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->auditLogger->record('mcp.connection_revoked', $actor, $connection->organization, auditable: $connection, newValues: ['name' => $connection->name]);
        });
    }

    /** @param list<string> $scopes */
    public function replaceScopes(McpConnection $connection, array $scopes): void
    {
        $connection->scopeRecords()->delete();
        foreach (array_values(array_unique($scopes)) as $scope) {
            $record = new McpConnectionScope;
            $record->mcp_connection_id = $connection->getKey();
            $record->scope = $scope;
            $record->save();
        }
        $connection->unsetRelation('scopeRecords');
    }

    private function newSecret(string $id): string
    {
        return '10xmcp_'.$id.'_'.bin2hex(random_bytes(32));
    }
}
