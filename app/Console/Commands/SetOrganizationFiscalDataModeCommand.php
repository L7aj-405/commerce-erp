<?php

namespace App\Console\Commands;

use App\Actions\Settings\SetOrganizationFiscalDataModeAction;
use App\Models\Organization;
use App\Services\Finance\FiscalDocumentProtection;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Platform-operator escape hatch: reclassify an organization's fiscal data.
 *
 * Every existing organization became `protected` when fiscal protection was
 * introduced. A genuine test / demonstration organization that already holds
 * issued invoices or payments can only be declared `test` from here (never
 * from the tenant UI), with an explicit reason and --force. Audited.
 */
class SetOrganizationFiscalDataModeCommand extends Command
{
    protected $signature = 'organization:fiscal-data-mode
        {organization : Organization id}
        {mode : protected|test}
        {--reason= : Mandatory justification recorded in the audit log}
        {--force : Required to declare as test an organization that already holds protected fiscal documents}';

    protected $description = 'Declare an organization\'s fiscal data as real (protected) or test data (platform operators only).';

    public function handle(SetOrganizationFiscalDataModeAction $action, FiscalDocumentProtection $fiscal): int
    {
        $organization = Organization::query()->find((int) $this->argument('organization'));
        if (! $organization) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }
        $mode = (string) $this->argument('mode');
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required.');

            return self::FAILURE;
        }

        $protected = array_sum($fiscal->protectedCounts($organization));
        if ($mode === FiscalDocumentProtection::MODE_TEST && $protected > 0 && ! $this->option('force')) {
            $this->error("Organization #{$organization->id} holds {$protected} protected fiscal document(s). Re-run with --force only if they are certainly test data.");

            return self::FAILURE;
        }

        try {
            $organization = $action->execute(null, $organization, $mode, $reason, platformOverride: true);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info("Organization #{$organization->id} fiscal data mode: {$organization->fiscal_data_mode}.");

        return self::SUCCESS;
    }
}
