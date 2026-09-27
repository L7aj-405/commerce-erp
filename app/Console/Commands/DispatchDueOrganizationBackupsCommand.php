<?php

namespace App\Console\Commands;

use App\Actions\OrganizationBackups\DispatchDueOrganizationBackupsAction;
use Illuminate\Console\Command;

class DispatchDueOrganizationBackupsCommand extends Command
{
    protected $signature = 'organization-backups:dispatch-due';

    protected $description = 'Dispatch queued jobs for due organization backups';

    public function handle(DispatchDueOrganizationBackupsAction $action): int
    {
        $count = $action->execute();
        $this->info("Dispatched {$count} organization backup job(s).");

        return self::SUCCESS;
    }
}
