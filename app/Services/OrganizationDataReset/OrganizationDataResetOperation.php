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
    public const UNASSIGN_NON_OWNER_FOLLOW_UPS = 'unassign_non_owner_follow_ups';
    public const UNASSIGN_NON_OWNER_RECURRING_RULES = 'unassign_non_owner_recurring_rules';
    public const DELETE_SALES_RESERVATION_GRAPH = 'delete_sales_reservation_graph';
    public const DELETE_SALES_INVENTORY_MOVEMENTS = 'delete_sales_inventory_movements';
    public const DELETE_SALES_WOOCOMMERCE_STOCK_TASKS = 'delete_sales_woocommerce_stock_tasks';
    public const DELETE_SALES_TRANSFER_REQUEST_LINES = 'delete_sales_transfer_request_lines';
    public const DELETE_SALES_STOCK_TRANSFER_LINES = 'delete_sales_stock_transfer_lines';
    public const DELETE_SALES_TRANSFER_GRAPH = 'delete_sales_transfer_graph';

    public function __construct(
        public string $key,
        public OrganizationDataResetScope $scope,
        public string $table,
        public string $action = self::DELETE,
        public ?string $column = null,
    ) {}
}
