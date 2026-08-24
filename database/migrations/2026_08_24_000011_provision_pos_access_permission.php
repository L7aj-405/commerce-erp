<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['key' => 'pos.access'],
            ['name' => 'Access the point of sale', 'updated_at' => $now, 'created_at' => $now],
        );

        $permissionId = DB::table('permissions')->where('key', 'pos.access')->value('id');

        DB::table('roles')
            ->whereIn('slug', ['owner', 'admin', 'sales-employee'])
            ->orderBy('id')
            ->each(function ($role) use ($permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'organization_id' => $role->organization_id,
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            });
    }

    public function down(): void
    {
        // Retained so rollback cannot silently revoke custom role assignments.
    }
};
