<?php

namespace App\Services\Mcp;

use App\Models\McpConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class McpExecutionContext
{
    private ?McpConnection $connection = null;

    public function bind(McpConnection $connection): void
    {
        if ($this->connection !== null) {
            throw new LogicException('The MCP execution context is already bound.');
        }

        $this->connection = $connection;
    }

    public function isBound(): bool { return $this->connection !== null; }
    public function connection(): ?McpConnection { return $this->connection; }

    public function connectionOrFail(): McpConnection
    {
        return $this->connection ?? throw new LogicException('No authenticated MCP connection is bound.');
    }

    public function organization(): Organization { return $this->connectionOrFail()->organization; }
    public function user(): User { return $this->connectionOrFail()->user; }

    public function allows(string $scope): bool
    {
        if (McpScopes::permissionsFor($scope) === []) {
            return false;
        }

        $connection = $this->connectionOrFail();

        // Current ERP permissions at every check: a revoked permission takes effect immediately.
        return in_array($scope, $connection->scopeKeys(), true)
            && McpScopes::grantedBy($scope, $connection->user->permissionKeysFor($connection->organization_id));
    }

    public function reset(): void
    {
        $this->connection = null;
    }

    public function authorize(string $scope): void
    {
        if (! $this->allows($scope)) {
            throw new AuthorizationException('The MCP connection is not authorized for this capability.');
        }
    }
}
