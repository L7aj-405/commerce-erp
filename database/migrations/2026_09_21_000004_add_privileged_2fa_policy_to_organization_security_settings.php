<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1.1 §7 — a policy distinct from the existing blanket `require_2fa`
 * (which gates every member): this one targets ONLY the organization's
 * owner/admin roles, since those can manage members, roles, integrations and
 * settings for the whole tenant. Column default is `false` at the schema
 * level (no destructive effect on any existing row) — the actual "on by
 * default" behavior for NEWLY created organizations is set in application
 * code (see OrganizationCreator), not here, so this migration never
 * retroactively changes what any existing organization enforces today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_security_settings', function (Blueprint $table) {
            $table->boolean('require_2fa_for_privileged_roles')->default(false)->after('require_2fa');
        });
    }

    public function down(): void
    {
        Schema::table('organization_security_settings', function (Blueprint $table) {
            $table->dropColumn('require_2fa_for_privileged_roles');
        });
    }
};
