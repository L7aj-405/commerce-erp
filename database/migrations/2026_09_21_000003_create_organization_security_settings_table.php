<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization-level security policy (§E7). `require_2fa`: when enabled,
 * members without a confirmed TOTP enrollment are redirected to set one up
 * before reaching organization business data (see EnsureTwoFactorPolicy
 * middleware) — enrollment itself stays reachable without 2FA already
 * configured (it cannot be a chicken-and-egg requirement). One row per
 * organization, created lazily on first policy change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_security_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('require_2fa')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_security_settings');
    }
};
