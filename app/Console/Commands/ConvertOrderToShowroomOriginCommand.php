<?php

namespace App\Console\Commands;

use App\Actions\Sales\ConvertOrderToShowroomOriginAction;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Explicit repair for ONE already-confirmed POS order: switch it from direct
 * dispatch to Showroom origin and recompute its transfers. Preview by default
 * (nothing is saved); `--apply` commits. See ConvertOrderToShowroomOriginAction
 * for the safety checks. Never run automatically.
 */
class ConvertOrderToShowroomOriginCommand extends Command
{
    protected $signature = 'sales:convert-to-showroom-origin
        {order_number : e.g. SO-000041}
        {--organization= : Organization id (required)}
        {--actor= : E-mail of the member performing the repair (required, recorded in the audit log)}
        {--apply : Commit the change (default: preview only, rolled back)}';

    protected $description = 'Preview / apply the conversion of a confirmed POS order to "Depuis le Showroom" fulfillment origin (explicit, audited, single order)';

    public function handle(ConvertOrderToShowroomOriginAction $action): int
    {
        $organizationId = (int) $this->option('organization');
        $actor = User::query()->where('email', (string) $this->option('actor'))->first();
        if ($organizationId === 0 || ! $actor) {
            $this->error('--organization and --actor (an existing user e-mail) are required.');

            return self::INVALID;
        }

        $order = SalesOrder::query()->withoutGlobalScopes()->whereNull('sales_orders.sandbox_id')
            ->where('organization_id', $organizationId)
            ->where('order_number', (string) $this->argument('order_number'))
            ->first();
        if (! $order) {
            $this->error('Order not found in this organization.');

            return self::FAILURE;
        }

        try {
            $result = $action->execute($actor, $order, (bool) $this->option('apply'));
        } catch (ValidationException $exception) {
            $this->error('Refused: '.collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info(($result['applied'] ? 'APPLIED' : 'PREVIEW (nothing saved)').' — '.$result['order']);
        foreach (['before' => $result['before'], 'after' => $result['after']] as $label => $requests) {
            $this->line(strtoupper($label).':');
            $rows = [];
            foreach ($requests as $request) {
                foreach ($request['lines'] as $line) {
                    $rows[] = [$request['request_number'], $request['status'], $request['sales_order_id'] ? 'client' : 'général', $line['product_variant_id'], $line['reason'], $line['quantity']];
                }
            }
            $this->table(['Demande', 'Statut', 'Type', 'Variante', 'Motif', 'Quantité'], $rows);
        }
        if (! $result['applied']) {
            $this->comment('Re-run with --apply to commit exactly these changes.');
        }

        return self::SUCCESS;
    }
}
