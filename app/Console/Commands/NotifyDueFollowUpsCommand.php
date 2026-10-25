<?php

namespace App\Console\Commands;

use App\Actions\FollowUps\NotifyDueFollowUpsAction;
use Illuminate\Console\Command;

class NotifyDueFollowUpsCommand extends Command
{
    protected $signature = 'follow-ups:notify-due {--limit=2000 : Maximum due reminders processed in this run}';

    protected $description = 'Publish in-app due reminders for Work Center follow-ups (idempotent, safe to overlap)';

    public function handle(NotifyDueFollowUpsAction $action): int
    {
        $result = $action->execute(limit: max(1, (int) $this->option('limit')));
        $this->info("Follow-up reminders: {$result['sent']} sent, {$result['skipped']} skipped, {$result['failed']} failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
