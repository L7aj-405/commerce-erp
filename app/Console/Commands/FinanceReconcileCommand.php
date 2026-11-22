<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * READ-ONLY financial / stock reconciliation report (Phase 1 Step 8, checks R-1 … R-10).
 * Writes nothing: no data, no audit rows, no queued work. Exit code: 0 = no variance, 1 = variance found,
 * 2 = invalid input. Run it per organization; the organization is an operator argument, never request input.
 */
class FinanceReconcileCommand extends Command
{
    protected $signature = 'finance:reconcile
        {organization : Organization id}
        {--month= : Check one month (YYYY-MM) for R-3/R-4; takes precedence over --months}
        {--months=12 : Number of months ending with the current business month (1-36)}
        {--sample=20 : Maximum offending rows listed per check}
        {--json : Machine-readable output}';

    protected $description = 'Read-only reconciliation report R-1…R-10 (receivables, accounts, monthly totals, payments, snapshots, stock, supplier invoices vs obligations and allocations)';

    public function handle(FinanceReconciliationService $reconciliation): int
    {
        $organization = ctype_digit((string) $this->argument('organization'))
            ? Organization::query()->find((int) $this->argument('organization'))
            : null;
        if (! $organization) {
            $this->error('Organization not found.');

            return 2;
        }

        try {
            $months = $this->months($organization);
        } catch (ValidationException $e) {
            $this->error(implode(' ', array_merge(...array_values($e->errors()))));

            return 2;
        }
        $sampleOption = (string) $this->option('sample');
        if (! ctype_digit($sampleOption) || (int) $sampleOption < 1 || (int) $sampleOption > 200) {
            $this->error('--sample must be an integer between 1 and 200.');

            return 2;
        }
        $sample = (int) $sampleOption;

        $results = $reconciliation->run($organization, $months, $sample);
        $failed = collect($results)->where('status', 'fail')->count();

        if ($this->option('json')) {
            $this->line(json_encode(['organization_id' => $organization->getKey(), 'months' => $months, 'ok' => $failed === 0, 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Reconciliation (read-only) — organization #{$organization->getKey()} — months: ".implode(', ', $months));
        foreach ($results as $r) {
            $line = sprintf('[%s] %s — %s', strtoupper($r['status']), $r['code'], $r['title']);
            $r['status'] === 'fail' ? $this->error($line) : $this->line($line);
            if ($r['expected'] !== null) {
                $this->line("      expected={$r['expected']} actual={$r['actual']} variance={$r['variance']} ({$r['classification']})");
            }
            if ($r['note']) {
                $this->line("      {$r['note']}");
            }
            if ($r['violations'] > 0) {
                $this->line("      violations: {$r['violations']}");
                foreach ($r['samples'] as $sampleRow) {
                    $this->line('        - '.json_encode($sampleRow, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        $failed === 0 ? $this->info('No variance found.') : $this->error("{$failed} check(s) show a variance.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function months(Organization $organization): array
    {
        if ($this->option('month')) {
            return [FinancePeriod::fromMonth((string) $this->option('month'))->month];
        }
        $monthsOption = (string) $this->option('months');
        $count = ctype_digit($monthsOption) ? (int) $monthsOption : 0;
        if ($count < 1 || $count > 36) {
            throw ValidationException::withMessages(['months' => '--months must be between 1 and 36.']);
        }
        $current = Carbon::createFromFormat('Y-m-d', FinancePeriod::current($organization)->month.'-01');

        return collect(range($count - 1, 0))->map(fn (int $back) => $current->copy()->subMonthsNoOverflow($back)->format('Y-m'))->all();
    }
}
