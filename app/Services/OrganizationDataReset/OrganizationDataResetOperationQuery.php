<?php

namespace App\Services\OrganizationDataReset;

use App\Models\CustomerReturn;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderProcurement;
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

        $query = $this->query($organization, $operation);

        return match ($operation->action) {
            OrganizationDataResetOperation::SET_NULL => $query->update([$operation->column => null]),
            OrganizationDataResetOperation::CLEAR_NON_OWNER_ACTIVE_CONTEXT => $query->update([
                'active_organization_id' => null,
                'active_store_id' => null,
            ]),
            default => $query->delete(),
        };
    }

    public function stateFingerprint(Organization $organization, OrganizationDataResetOperation $operation): string
    {
        if (! Schema::hasTable($operation->table)) {
            return hash('sha256', 'missing');
        }

        $rows = $operation->action === OrganizationDataResetOperation::DELETE_SALES_TRANSFER_GRAPH
            ? $this->salesTransferGraphRows((int) $organization->getKey())
            : $this->query($organization, $operation)->get();
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
            OrganizationDataResetOperation::DELETE_SALES_INVENTORY_RESERVATIONS => DB::table('inventory_reservations')
                ->where('organization_id', $organizationId)
                ->where('reference_type', SalesOrderInventoryAllocation::class),
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
                        ->whereIn('reference_id', DB::table('inventory_reservations')
                            ->select('id')
                            ->where('organization_id', $organizationId)
                            ->where('reference_type', SalesOrderInventoryAllocation::class));
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
