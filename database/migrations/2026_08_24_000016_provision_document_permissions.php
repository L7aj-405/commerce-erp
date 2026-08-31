<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'invoices.view' => 'View invoices',
        'invoices.create' => 'Create invoice drafts',
        'invoices.update_draft' => 'Update invoice drafts',
        'invoices.issue' => 'Issue invoices',
        'invoices.backdate' => 'Use an invoice date other than today',
        'delivery_notes.view' => 'View delivery notes',
        'delivery_notes.create' => 'Create delivery note drafts',
        'delivery_notes.update_draft' => 'Update delivery note drafts',
        'delivery_notes.issue' => 'Issue delivery notes',
        'delivery_notes.backdate' => 'Use a delivery date other than today',
    ];

    private const SALES_EMPLOYEE_KEYS = [
        'invoices.view', 'invoices.create', 'invoices.update_draft', 'invoices.issue',
        'delivery_notes.view', 'delivery_notes.create', 'delivery_notes.update_draft', 'delivery_notes.issue',
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
