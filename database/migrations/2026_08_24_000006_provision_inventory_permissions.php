<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'warehouses.view' => 'View warehouses',
        'warehouses.create' => 'Create warehouses',
        'warehouses.update' => 'Update warehouses',
        'inventory.view' => 'View inventory',
        'inventory.opening' => 'Add opening stock',
        'inventory.adjust' => 'Adjust inventory',
        'inventory.reserve' => 'Reserve inventory',
        'inventory.release' => 'Release inventory reservations',
        'inventory.consume' => 'Consume inventory reservations',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'updated_at' => $now, 'created_at' => $now]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id', 'key');
        DB::table('roles')->whereIn('slug', ['owner', 'admin', 'sales-employee'])->orderBy('id')->each(function ($role) use ($permissionIds) {
            $keys = $role->slug === 'sales-employee' ? ['inventory.view'] : array_keys(self::PERMISSIONS);
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
