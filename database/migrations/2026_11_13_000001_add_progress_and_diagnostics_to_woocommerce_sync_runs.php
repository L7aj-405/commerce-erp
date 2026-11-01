<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3 — observable, rate-limit-aware WooCommerce synchronization.
 *
 * - phase: where the run is (starting, loading_settings, loading_categories,
 *   loading_products, loading_variations, importing_products, rate_limited /
 *   retrying while a backoff is active, completed, failed);
 * - progress: safe live position (page / total pages, product id, retry_at…);
 * - failure_reason: the API failure category of a failed run (rate_limited,
 *   auth, unreachable, api_unavailable, invalid_response, ssrf_blocked, unexpected)
 *   and failed_during: the human location ("Produits — page 3 / 12");
 * - api_diagnostics: the last throttled / failed WooCommerce requests (endpoint
 *   path, page, product id, status, attempt, Retry-After, rate-limit headers,
 *   duration) — never credentials or bodies;
 * - api_requests / rate_limit_hits: request counters of the run.
 *
 * Additive only; existing rows keep NULL / 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_sync_runs', function (Blueprint $table) {
            $table->string('phase', 32)->nullable()->after('status');
            $table->json('progress')->nullable()->after('phase');
            $table->string('failure_reason', 32)->nullable()->after('progress');
            $table->string('failed_during', 255)->nullable()->after('failure_reason');
            $table->json('api_diagnostics')->nullable()->after('errors');
            $table->unsignedInteger('api_requests')->default(0)->after('stock_adjustments');
            $table->unsignedInteger('rate_limit_hits')->default(0)->after('api_requests');
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['phase', 'progress', 'failure_reason', 'failed_during', 'api_diagnostics', 'api_requests', 'rate_limit_hits']);
        });
    }
};
