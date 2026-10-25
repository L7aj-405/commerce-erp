<?php

namespace App\Console\Commands;

use App\Services\FollowUps\FollowUpRecurringGenerator;
use Illuminate\Console\Command;

class GenerateRecurringFollowUpsCommand extends Command
{
    protected $signature = 'follow-ups:generate-recurring {--limit=1000 : Maximum recurring rules processed in this run}';

    protected $description = 'Generate due occurrences of recurring Work Center follow-ups (idempotent, safe to overlap)';

    public function handle(FollowUpRecurringGenerator $generator): int
    {
        $result = $generator->generateDue(limit: max(1, (int) $this->option('limit')));
        $this->info("Recurring follow-ups: {$result['created']} occurrence(s) created across {$result['rules']} rule(s), {$result['failed']} failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
