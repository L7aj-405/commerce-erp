<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'financial_accounts.view' => 'View financial accounts',
        'financial_accounts.create' => 'Create financial accounts',
        'financial_accounts.update' => 'Update financial accounts',
        'payments.view' => 'View payments',
        'payments.create' => 'Record payments',
        'payments.reverse' => 'Reverse posted payments',
        'payments.backdate' => 'Record payments on a date other than today',
    ];

    private const SALES_EMPLOYEE_KEYS = ['payments.view', 'payments.create'];

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
