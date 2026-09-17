<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP two-factor authentication (§E). Belongs on `users` — 2FA is a property
 * of the account, not of any one organization it belongs to (§I). The secret
 * and recovery codes are stored via the model's `encrypted` cast (same
 * convention as woocommerce_integrations.consumer_secret /
 * organization_mail_settings.smtp_password) — never plaintext at rest.
 * `two_factor_confirmed_at` distinguishes "secret generated, awaiting the
 * user's first correct code" from "actually enabled" — a secret must never be
 * treated as active before that confirmation (§E1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->dateTime('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
