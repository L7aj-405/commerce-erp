<?php

namespace App\Console\Commands;

use App\Actions\Inventory\RestoreStockTransfersFromLedgerAction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Explicit, audited repair of completed transfers missing from "Transferts
 * effectués" while their ledger movements exist. Preview by default; `--apply`
 * writes. Never changes stock quantities nor movements.
 */
class RestoreStockTransfersCommand extends Command
{
    protected $signature = 'inventory:restore-stock-transfers
        {--organization= : Organization id (required)}
        {--actor= : E-mail of the member performing the repair (owner or system.data_reset, recorded in the audit log)}
        {--apply : Write the records (default: preview only)}';

    protected $description = 'Preview / rebuild completed stock transfer records (TRF) from their ledger movements when the record is missing';

    public function handle(RestoreStockTransfersFromLedgerAction $action): int
    {
        $organization = Organization::query()->find((int) $this->option('organization'));
        $actor = User::query()->where('email', (string) $this->option('actor'))->first();
        if (! $organization || ! $actor) {
            $this->error('--organization (id) and --actor (an existing user e-mail) are required.');

            return self::INVALID;
        }

        $result = $action->execute($actor, $organization, (bool) $this->option('apply'));

        $this->info(($result['applied'] ? 'APPLIED' : 'PREVIEW (nothing written)').' — '.$organization->name);
        $this->table(['Transfert', 'Source → destination', 'Unités', 'Date', 'Lignes'], array_map(fn (array $g) => [
            $g['number'], $g['source_warehouse_id'].' → '.$g['destination_warehouse_id'], $g['unit_count'], $g['transferred_at'], count($g['lines']),
        ], $result['restorable']));
        foreach ($result['refused'] as $refused) {
            $this->warn("refused {$refused['number']}: {$refused['reason']}");
        }
        if ($result['restorable'] === [] && $result['refused'] === []) {
            $this->line('Nothing to restore.');
        }
        if (! $result['applied'] && $result['restorable'] !== []) {
            $this->comment('Re-run with --apply to write exactly these records.');
        }

        return self::SUCCESS;
    }
}
