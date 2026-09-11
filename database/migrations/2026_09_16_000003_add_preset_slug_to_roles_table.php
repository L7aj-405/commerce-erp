<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users, Roles & Permissions V1.
 *
 * `preset_slug` is descriptive/UI metadata only — it records which preset
 * (see config('platform.presets')) a custom role was created from, so the UI
 * can show "based on Finance" etc. It is NEVER read by hasPermission() or any
 * authorization check: the role's actual permissions live in `role_permission`
 * exactly as before. Existing rows backfill to NULL (no behavior change).
 *
 * Additive-only per MIGRATION_BASELINE.md: appended after the frozen baseline,
 * does not touch `2026_08_23_000001_create_platform_core_tables`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('preset_slug')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('preset_slug');
        });
    }
};
