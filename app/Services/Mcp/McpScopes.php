<?php

namespace App\Services\Mcp;

/**
 * The single reading of config('mcp.scopes'): which ERP permission(s) a scope
 * requires. A scope is an extra ceiling, never a grant — the connection user
 * must still hold the ERP permission at call time, and the canonical services
 * then apply their own permission/store rules to every record.
 *
 * Most scopes mirror one ERP permission (`permission`). W6 cross-document
 * capabilities (attention, timeline) have no single permission: they declare
 * `any_permission` and are available to a user holding at least one; which
 * documents they then reveal is decided per record by the canonical service.
 */
final class McpScopes
{
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return config('mcp.scopes', []);
    }

    public static function exists(string $scope): bool
    {
        return isset(self::definitions()[$scope]);
    }

    /** @return list<string> */
    public static function permissionsFor(string $scope): array
    {
        $definition = self::definitions()[$scope] ?? [];
        $permissions = is_string($definition['permission'] ?? null) ? [$definition['permission']] : ($definition['any_permission'] ?? []);

        return array_values(array_filter($permissions, 'is_string'));
    }

    /** Whether a user holding `$permissionKeys` may hold this scope. @param list<string> $permissionKeys */
    public static function grantedBy(string $scope, array $permissionKeys): bool
    {
        return array_intersect(self::permissionsFor($scope), $permissionKeys) !== [];
    }
}
