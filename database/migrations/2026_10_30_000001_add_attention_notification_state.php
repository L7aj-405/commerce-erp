<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W4.2: critical attention notifications and urgency ordering.
 *
 * - notification_processed_at: the episode's one notification decision was
 *   made (delivered, no eligible recipient, or Sales notifications off) —
 *   never processed twice, so an episode notifies at most once.
 * - notified_at: a notification was actually delivered for this episode.
 * - urgency: transparent within-severity ordering value
 *   (QuotationAttentionDetector::urgency), refreshed by every scan.
 *
 * Additive. Critical items that already exist are marked processed (no burst
 * of notifications for situations detected before W4.2); urgency fills in on
 * the next scan. The candidate query (organization_id, status, severity …)
 * uses attention_org_status_idx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attention_items', function (Blueprint $table) {
            $table->unsignedInteger('urgency')->default(0)->after('severity');
            $table->timestamp('notification_processed_at')->nullable()->after('dismiss_note');
            $table->timestamp('notified_at')->nullable()->after('notification_processed_at');
        });

        DB::table('attention_items')->where('severity', 'critical')->update(['notification_processed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('attention_items', function (Blueprint $table) {
            $table->dropColumn(['urgency', 'notification_processed_at', 'notified_at']);
        });
    }
};
