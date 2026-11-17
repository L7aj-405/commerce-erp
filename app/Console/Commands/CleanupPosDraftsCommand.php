<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SalesOrder;
use App\Services\Pos\PosDraftLifecycle;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reports (default, read-only) or removes (--execute) abandoned EMPTY POS carts. Carts that hold
 * products are never removed here: those idle past the inactivity window are only reported.
 *
 * Scope: Production records only (no Test Mode context is bound in the CLI, so
 * the sandbox scope resolves to the Production environment), POS-origin drafts
 * only, one organization at a time. Anything with fiscal, payment, delivery,
 * return or procurement history is reported as blocked and never touched.
 */
class CleanupPosDraftsCommand extends Command
{
    protected $signature = 'pos:cleanup-drafts
        {--execute : Actually discard eligible drafts. Without it the command is a read-only dry run.}
        {--organization= : Restrict to one organization id}
        {--batch= : Maximum drafts examined per run (default pos.drafts.cleanup_batch_size)}';

    protected $description = 'Dry-run (default) or execute the cleanup of EMPTY abandoned POS cart drafts (never carts with products)';

    public function handle(PosDraftLifecycle $lifecycle, SandboxContext $sandbox): int
    {
        if ($sandbox->isSandbox()) {
            $this->error('Refusing to run inside a Test Mode context.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $batch = max(1, min(1000, (int) ($this->option('batch') ?: config('pos.drafts.cleanup_batch_size', 200))));
        $examined = 0;
        $totals = ['empty_eligible' => 0, 'attention' => 0, 'blocked' => 0, 'active' => 0, 'reservations' => 0, 'discarded' => 0, 'skipped' => 0, 'failed' => 0];
        $blockedReasons = [];

        $graceCutoff = $lifecycle->emptyGraceCutoff();
        $organizationIds = $lifecycle->candidates()
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', (int) $id))
            ->distinct()->orderBy('organization_id')->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $organization = Organization::query()->find($organizationId);
            if (! $organization || $organization->status !== 'active') {
                continue;
            }
            $lifecycle->candidates($organization)
                // Superset prefilter (the shorter empty-cart grace); exact rules are rechecked per row.
                ->whereRaw('COALESCE(pos_last_activity_at, updated_at, created_at) < ?', [$graceCutoff])
                ->chunkById(100, function ($orders) use (&$examined, $batch, $execute, $lifecycle, &$totals, &$blockedReasons) {
                    foreach ($orders as $order) {
                        if ($examined >= $batch) {
                            return false;
                        }
                        $examined++;
                        $this->process($order, $execute, $lifecycle, $totals, $blockedReasons);
                    }

                    return true;
                });
            if ($examined >= $batch) {
                break;
            }
        }

        $this->line('Scheduled cleanup gate (POS_DRAFT_CLEANUP_ENABLED): '.(config('pos.drafts.cleanup_enabled') ? 'ENABLED' : 'DISABLED (default) — only this manual command can remove empty carts'));
        $this->line($execute ? 'POS draft cleanup — EXECUTED' : 'POS draft cleanup — DRY RUN (nothing was changed; use --execute to apply)');
        $this->table(['Metric', 'Count'], [
            ['Examined (batch limit '.$batch.')', $examined],
            ['Eligible empty drafts', $totals['empty_eligible']],
            ['Non-empty drafts idle > '.config('pos.drafts.inactivity_hours').' h (flagged for attention, NEVER deleted)', $totals['attention']],
            ['Blocked (history / reservations)', $totals['blocked']],
            ['Still active (not expired)', $totals['active']],
            ['Active reservations tied to examined drafts', $totals['reservations']],
            ['Discarded', $totals['discarded']],
            ['Skipped (changed under lock)', $totals['skipped']],
            ['Failed', $totals['failed']],
        ]);
        if ($blockedReasons !== []) {
            ksort($blockedReasons);
            $this->line('Blocked reasons: '.collect($blockedReasons)->map(fn ($n, $r) => "{$r}={$n}")->implode(', '));
        }

        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, int> $totals @param array<string, int> $blockedReasons */
    private function process(SalesOrder $order, bool $execute, PosDraftLifecycle $lifecycle, array &$totals, array &$blockedReasons): void
    {
        $class = $lifecycle->classify($order);
        $totals['reservations'] += $class['reservations'];
        if ($class['state'] === 'blocked') {
            $totals['blocked']++;
            foreach ($class['reasons'] as $reason) {
                $blockedReasons[$reason] = ($blockedReasons[$reason] ?? 0) + 1;
            }

            return;
        }
        if ($class['state'] === 'active') {
            $totals['active']++;

            return;
        }
        $totals[$class['state']]++;
        if (! $execute || $class['state'] !== 'empty_eligible') {
            // Dry run, or a cart with products: report only.
            return;
        }
        try {
            $result = $lifecycle->discard(null, $order, PosDraftLifecycle::REASON_EMPTY);
            match ($result['outcome']) {
                PosDraftLifecycle::OUTCOME_DISCARDED => $totals['discarded']++,
                default => $totals['skipped']++,
            };
        } catch (Throwable $exception) {
            $totals['failed']++;
            report($exception);
        }
    }
}
