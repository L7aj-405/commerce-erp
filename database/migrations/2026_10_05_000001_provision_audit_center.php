<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['organization_id', 'actor_id', 'created_at'], 'audit_org_actor_date_idx');
            $table->index(['organization_id', 'event', 'created_at'], 'audit_org_event_date_idx');
        });

        app(PermissionProvisioner::class)->provisionAllOrganizations();
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_org_actor_date_idx');
            $table->dropIndex('audit_org_event_date_idx');
        });
        // Keep permission rows and assignments to avoid silently revoking access.
    }
};
