<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'customers.view' => 'View customers',
        'customers.create' => 'Create customers',
        'customers.update' => 'Update customers',
        'sales_orders.view' => 'View sales orders',
        'sales_orders.create' => 'Create sales orders',
        'sales_orders.update' => 'Update draft sales orders',
        'sales_orders.confirm' => 'Confirm sales orders',
        'sales_orders.cancel' => 'Cancel sales orders',
        'sales_orders.fulfill' => 'Fulfill sales orders',
        'sales_orders.override_price' => 'Override catalog sale prices',
        'sales_orders.apply_discount' => 'Apply sales order line discounts',
    ];

    private const SALES_EMPLOYEE_KEYS = [
        'customers.view', 'customers.create', 'customers.update',
        'sales_orders.view', 'sales_orders.create', 'sales_orders.update',
        'sales_orders.confirm', 'sales_orders.fulfill',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'updated_at' => $now, 'created_at' => $now]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id', 'key');
        DB::table('roles')->whereIn('slug', ['owner', 'admin', 'sales-employee'])->orderBy('id')->each(function ($role) use ($permissionIds) {
            $keys = $role->slug === 'sales-employee' ? self::SALES_EMPLOYEE_KEYS : array_keys(self::PERMISSIONS);
            foreach ($keys as $key) {
                DB::table('role_permission')->insertOrIgnore([
                    'organization_id' => $role->organization_id,
                    'role_id' => $role->id,
                    'permission_id' => $permissionIds[$key],
                ]);
            }
        });
    }

    public function down(): void
    {
        // Retained so rollback cannot silently revoke custom role assignments.
    }
};
