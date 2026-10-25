<?php

use App\Models\FollowUp;
use App\Models\Organization;
use App\Services\FollowUps\FollowUpReminderSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W2: due-reminder state on follow-ups (see FollowUpReminderSchedule).
 *
 * - `remind_at`   UTC instant of the pending reminder occurrence; NULL when
 *                 nothing is pending. The scheduler's only access path:
 *                 `remind_at <= now`, served by follow_up_remind_at_idx.
 * - `notified_at` UTC time the current occurrence's notification was actually
 *                 published (shown as "reminder sent"); reset when the due
 *                 value changes. Delivery history lives in user_notifications.
 *
 * Additive and nullable. Existing open follow-ups are armed only for
 * occurrences still in the future (no burst of reminders for old overdue
 * work), with set-based updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->timestamp('remind_at')->nullable()->after('due_at');
            $table->timestamp('notified_at')->nullable()->after('remind_at');
            $table->index('remind_at', 'follow_up_remind_at_idx');
        });

        $now = CarbonImmutable::now('UTC');

        // Timed: the reminder is the due instant itself — one set-based update.
        DB::table('follow_ups')->where('status', 'open')->whereNotNull('due_at')->where('due_at', '>', $now)
            ->update(['remind_at' => DB::raw('due_at')]);

        // Date-only: 09:00 local per (organization, due_date) — one update per
        // distinct pair, streamed. Dates before yesterday (UTC) can no longer be
        // in the future in any timezone.
        $schedule = new FollowUpReminderSchedule;
        $organizations = [];
        DB::table('follow_ups')->where('status', 'open')->whereNull('due_at')
            ->where('due_date', '>=', $now->subDay()->toDateString())
            ->select(['organization_id', 'due_date'])->distinct()
            ->orderBy('organization_id')->orderBy('due_date')
            ->lazy(500)
            ->each(function (object $pair) use ($schedule, $now, &$organizations) {
                $organization = $organizations[$pair->organization_id] ??= Organization::query()->find($pair->organization_id);
                $probe = new FollowUp;
                $probe->due_date = (string) $pair->due_date;
                $instant = $organization ? $schedule->instant($organization, $probe) : null;
                if ($instant !== null && $instant->greaterThan($now)) {
                    DB::table('follow_ups')->where('organization_id', $pair->organization_id)->where('due_date', $pair->due_date)
                        ->where('status', 'open')->whereNull('due_at')->update(['remind_at' => $instant]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_up_remind_at_idx');
            $table->dropColumn(['remind_at', 'notified_at']);
        });
    }
};
