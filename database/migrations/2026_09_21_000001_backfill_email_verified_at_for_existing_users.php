<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: email verification is now enforced for the whole
 * authenticated app (routes/web.php — `verified` middleware). Every account
 * that existed before this requirement shipped registered under the old
 * trust model and already owns real organizations/data — retroactively
 * locking them out would be a self-inflicted outage, not a security
 * improvement. Grandfather them in by stamping email_verified_at with their
 * original registration time; only accounts created AFTER this migration
 * (email_verified_at NULL by default) are actually gated by the new
 * requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Intentionally a no-op: reverting would re-lock every pre-existing
        // account out of the app it was already using.
    }
};
