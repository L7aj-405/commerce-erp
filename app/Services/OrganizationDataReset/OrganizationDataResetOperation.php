<?php

namespace App\Services\OrganizationDataReset;

final readonly class OrganizationDataResetOperation
{
    public const DELETE = 'delete';
    public const SET_NULL = 'set_null';
    public const DELETE_NON_OWNER_STORE_MEMBERSHIPS = 'delete_non_owner_store_memberships';
    public const DELETE_NON_OWNER_MEMBERSHIPS = 'delete_non_owner_memberships';
    public const DELETE_CUSTOM_ROLE_PERMISSIONS = 'delete_custom_role_permissions';
    public const DELETE_CUSTOM_ROLES = 'delete_custom_roles';
    public const CLEAR_NON_OWNER_ACTIVE_CONTEXT = 'clear_non_owner_active_context';

    public function __construct(
        public string $key,
        public OrganizationDataResetScope $scope,
        public string $table,
        public string $action = self::DELETE,
        public ?string $column = null,
    ) {}
}
