<?php

namespace App\Services\OrganizationDataReset;

use App\Models\CustomerReturn;
use App\Models\CustomerExchange;
use App\Models\DeliveryNote;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderProcurement;
use App\Support\InventoryQuantity;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrganizationDataResetOperationQuery
{
    public function count(Organization $organization, OrganizationDataResetOperation $operation): int
    {
        if (! Schema::hasTable($operation->table)) {
            return 0;
        }

        if ($operation->action === OrganizationDataResetOperation::DELETE_SALES_TRANSFER_GRAPH) {
            return $this->salesTransferGraphRows((int) $organization->getKey())->count();
        }
        if ($operation->action === OrganizationDataResetOperation::DELETE_SALES_RESERVATION_GRAPH) {
            return $this->salesReservationGraphRows((int) $organization->getKey())->count();
        }

        return $this->query($organization, $operation)->count();
    }

    public function execute(Organization $organization, OrganizationDataResetOperation $operation): int
    {
        if (! Schema::hasTable($operation->table)) {
            return 0;
        }

        if ($operation->action === OrganizationDataResetOperation::DELETE_SALES_TRANSFER_GRAPH) {
            return $this->deleteSalesTransferGraph((int) $organization->getKey());
        }
        if ($operation->action === OrganizationDataResetOperation::DELETE_SALES_RESERVATION_GRAPH) {
            return $this->deleteSalesReservationGraph((int) $organization->getKey());
        }

        $query = $this->query($organization, $operation);

        return match ($operation->action) {
            OrganizationDataResetOperation::SET_NULL => $query->update([$operation->column => null]),
            OrganizationDataResetOperation::CLEAR_NON_OWNER_ACTIVE_CONTEXT => $query->update([
                'active_organization_id' => null,
                'active_store_id' => null,
            ]),
            // Audit rows are protected during a reset: the reset's own audit
            // event records how many follow-ups were released (per operation key).
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_FOLLOW_UPS => $query->update([
                'assigned_user_id' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]),
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_ATTENTION => $query->update([
                'assigned_user_id' => null,
                'assignment_source' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]),
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_RECURRING_RULES => $query->update([
                'assigned_user_id' => null,
                'status' => 'paused',
                'status_reason' => 'assignee_lost',
                'next_occurrence_on' => null,
                'next_generate_at' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]),
            default => $query->delete(),
        };
    }

    public function stateFingerprint(Organization $organization, OrganizationDataResetOperation $operation): string
    {
        if (! Schema::hasTable($operation->table)) {
            return hash('sha256', 'missing');
        }

        $rows = match ($operation->action) {
            OrganizationDataResetOperation::DELETE_SALES_TRANSFER_GRAPH => $this->salesTransferGraphRows((int) $organization->getKey()),
            OrganizationDataResetOperation::DELETE_SALES_RESERVATION_GRAPH => $this->salesReservationGraphRows((int) $organization->getKey()),
            default => $this->query($organization, $operation)->get(),
        };
        $rowHashes = $rows
            ->map(function (object $row): string {
                $values = (array) $row;
                ksort($values);

                return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            })
            ->sort()
            ->values()
            ->all();

        return hash('sha256', implode('|', $rowHashes));
    }

    private function query(Organization $organization, OrganizationDataResetOperation $operation): Builder
    {
        $organizationId = (int) $organization->getKey();
        $ownerId = (int) $organization->owner_id;

        return match ($operation->action) {
            OrganizationDataResetOperation::SET_NULL => DB::table($operation->table)
                ->where('organization_id', $organizationId)
                ->whereNotNull($operation->column),
            OrganizationDataResetOperation::CLEAR_NON_OWNER_ACTIVE_CONTEXT => DB::table('users')
                ->where('id', '!=', $ownerId)
                ->where(function ($query) use ($organizationId) {
                    $query->where('active_organization_id', $organizationId)
                        ->orWhereIn('active_store_id', DB::table('stores')->select('id')->where('organization_id', $organizationId));
                }),
            OrganizationDataResetOperation::DELETE_NON_OWNER_STORE_MEMBERSHIPS => DB::table('store_memberships')
                ->where('organization_id', $organizationId)
                ->where('user_id', '!=', $ownerId),
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_FOLLOW_UPS => DB::table('follow_ups')
                ->where('organization_id', $organizationId)
                ->where('status', 'open')
                ->whereNotNull('assigned_user_id')
                ->where('assigned_user_id', '!=', $ownerId),
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_RECURRING_RULES => DB::table('follow_up_recurring_rules')
                ->where('organization_id', $organizationId)
                ->where('status', '!=', 'ended')
                ->whereNotNull('assigned_user_id')
                ->where('assigned_user_id', '!=', $ownerId),
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_ATTENTION => DB::table('attention_items')
                ->where('organization_id', $organizationId)
                ->whereNotNull('active_key')
                ->whereNotNull('assigned_user_id')
                ->where('assigned_user_id', '!=', $ownerId),
            OrganizationDataResetOperation::DELETE_DOCUMENT_ATTENTION => DB::table('attention_items')
                ->where('organization_id', $organizationId)
                ->whereIn('subject_type', ['quotation', 'invoice', 'sales_order']),
            OrganizationDataResetOperation::DELETE_NON_OWNER_MEMBERSHIPS => DB::table('organization_memberships')
                ->where('organization_id', $organizationId)
                ->where('user_id', '!=', $ownerId),
            OrganizationDataResetOperation::DELETE_CUSTOM_ROLE_PERMISSIONS => DB::table('role_permission')
                ->where('organization_id', $organizationId)
                ->whereIn('role_id', DB::table('roles')->select('id')
                    ->where('organization_id', $organizationId)->where('is_system', false)),
            OrganizationDataResetOperation::DELETE_CUSTOM_ROLES => DB::table('roles')
                ->where('organization_id', $organizationId)
                ->where('is_system', false),
            OrganizationDataResetOperation::DELETE_SALES_INVENTORY_MOVEMENTS => $this->salesInventoryMovements($organizationId),
            OrganizationDataResetOperation::DELETE_SALES_WOOCOMMERCE_STOCK_TASKS => DB::table('woocommerce_stock_tasks')
                ->where('organization_id', $organizationId)
                ->where('source_type', InventoryMovement::class)
                ->whereIn('source_id', $this->salesInventoryMovements($organizationId)->select('id')),
            OrganizationDataResetOperation::DELETE_SALES_TRANSFER_REQUEST_LINES => DB::table('transfer_request_lines')
                ->where('organization_id', $organizationId)
                ->whereIn('transfer_request_id', $this->salesTransferRequests($organizationId)->select('id')),
            OrganizationDataResetOperation::DELETE_SALES_STOCK_TRANSFER_LINES => DB::table('stock_transfer_lines')
                ->where('organization_id', $organizationId)
                ->whereIn('stock_transfer_id', $this->salesStockTransferIds($organizationId)),
            default => DB::table($operation->table)->where('organization_id', $organizationId),
        };
    }

    private function salesTransferGraphRows(int $organizationId): \Illuminate\Support\Collection
    {
        $requests = $this->salesTransferRequests($organizationId)->get()->map(function (object $row): object {
            $row->__reset_table = 'transfer_requests';

            return $row;
        });
        $transfers = DB::table('stock_transfers')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $this->salesStockTransferIds($organizationId))
            ->get()
            ->map(function (object $row): object {
                $row->__reset_table = 'stock_transfers';

                return $row;
            });

        return $requests->concat($transfers);
    }

    private function salesReservationGraphRows(int $organizationId): \Illuminate\Support\Collection
    {
        $allocations = DB::table('sales_order_inventory_allocations')
            ->where('organization_id', $organizationId)
            ->get()
            ->map(function (object $row): object {
                $row->__reset_table = 'sales_order_inventory_allocations';

                return $row;
            });
        $reservations = $this->salesReservations($organizationId)
            ->get()
            ->map(function (object $row): object {
                $row->__reset_table = 'inventory_reservations';

                return $row;
            });

        return $allocations->concat($reservations);
    }

    private function deleteSalesReservationGraph(int $organizationId): int
    {
        $reservations = $this->salesReservations($organizationId)
            ->lockForUpdate()
            ->get(['id', 'warehouse_id', 'product_variant_id', 'quantity', 'status']);
        $active = $reservations->where('status', 'active')
            ->groupBy(fn (object $reservation) => $reservation->warehouse_id.'|'.$reservation->product_variant_id);

        foreach ($active as $group) {
            $first = $group->first();
            $balance = DB::table('inventory_balances')
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $first->warehouse_id)
                ->where('product_variant_id', $first->product_variant_id)
                ->lockForUpdate()
                ->first(['id', 'reserved']);
            if (! $balance) {
                throw new OrganizationDataResetException('A Sales reservation has no inventory balance projection.');
            }
            $quantity = $group->reduce(
                fn (string $carry, object $reservation) => InventoryQuantity::add($carry, (string) $reservation->quantity),
                '0.0000',
            );
            if (InventoryQuantity::compare((string) $balance->reserved, $quantity) < 0) {
                throw new OrganizationDataResetException('A Sales reservation exceeds its inventory balance projection.');
            }
            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'reserved' => InventoryQuantity::subtract((string) $balance->reserved, $quantity),
                'updated_at' => now(),
            ]);
        }

        $reservationIds = $reservations->pluck('id');
        $allocations = DB::table('sales_order_inventory_allocations')
            ->where('organization_id', $organizationId)
            ->delete();
        $deletedReservations = $reservationIds->isEmpty()
            ? 0
            : DB::table('inventory_reservations')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $reservationIds)
                ->delete();

        return $allocations + $deletedReservations;
    }

    private function salesReservations(int $organizationId): Builder
    {
        $salesTypes = [
            SalesOrderInventoryAllocation::class,
            SalesOrder::class,
            SalesOrderLine::class,
            DeliveryNote::class,
            CustomerReturn::class,
            CustomerExchange::class,
            'sales_order',
            'sales_order_line',
            'delivery_note',
            'customer_return',
            'customer_exchange',
        ];

        return DB::table('inventory_reservations')
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($organizationId, $salesTypes) {
                $query->whereIn('reference_type', $salesTypes)
                    ->orWhereIn('id', DB::table('sales_order_inventory_allocations')
                        ->select('inventory_reservation_id')
                        ->where('organization_id', $organizationId)
                        ->whereNotNull('inventory_reservation_id'));
            });
    }

    private function deleteSalesTransferGraph(int $organizationId): int
    {
        $transferIds = $this->salesStockTransferIds($organizationId)->pluck('stock_transfer_id');
        $requests = $this->salesTransferRequests($organizationId)->delete();
        $transfers = $transferIds->isEmpty()
            ? 0
            : DB::table('stock_transfers')->where('organization_id', $organizationId)->whereIn('id', $transferIds)->delete();

        return $requests + $transfers;
    }

    private function salesTransferRequests(int $organizationId): Builder
    {
        return DB::table('transfer_requests')
            ->where('organization_id', $organizationId)
            ->whereNotNull('sales_order_id');
    }

    private function salesStockTransferIds(int $organizationId): Builder
    {
        return $this->salesTransferRequests($organizationId)
            ->select('stock_transfer_id')
            ->whereNotNull('stock_transfer_id');
    }

    private function salesInventoryMovements(int $organizationId): Builder
    {
        return DB::table('inventory_movements')
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($organizationId) {
                $query->where(function (Builder $reservationMovements) use ($organizationId) {
                    $reservationMovements
                        ->where('reference_type', InventoryReservation::class)
                        ->whereIn('reference_id', $this->salesReservations($organizationId)->select('id'));
                })->orWhere(function (Builder $returnMovements) use ($organizationId) {
                    $returnMovements
                        ->where('reference_type', CustomerReturn::class)
                        ->whereIn('reference_id', DB::table('customer_returns')
                            ->select('id')->where('organization_id', $organizationId));
                })->orWhere(function (Builder $procurementMovements) use ($organizationId) {
                    $procurementMovements
                        ->where('reference_type', SalesOrderProcurement::class)
                        ->whereIn('reference_id', DB::table('sales_order_procurements')
                            ->select('id')->where('organization_id', $organizationId));
                })->orWhere(function (Builder $transferMovements) use ($organizationId) {
                    $transferMovements
                        ->where('reference_type', 'inventory_transfer')
                        ->whereIn('reference', DB::table('stock_transfers')
                            ->select('transfer_number')
                            ->where('organization_id', $organizationId)
                            ->whereIn('id', $this->salesStockTransferIds($organizationId)));
                });
            });
    }
}
