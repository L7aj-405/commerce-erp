<?php

namespace App\Services\OrganizationDataReset;

class OrganizationDataResetLogicalReferenceRegistry
{
    /** @return array<string,array{policy:string,columns:list<string>}> */
    public function all(): array
    {
        return [
            'audit_logs' => ['policy' => 'preserve', 'columns' => ['auditable_type', 'auditable_id']],
            'document_email_deliveries' => ['policy' => 'preserve', 'columns' => ['document_type', 'document_id']],
            'user_notifications' => ['policy' => 'preserve', 'columns' => ['source_type', 'source_id']],
            'document_stamp_appositions' => ['policy' => 'delete_with_documents', 'columns' => ['stampable_type', 'stampable_id']],
            'inventory_movements' => ['policy' => 'delete_with_stock', 'columns' => ['reference_type', 'reference_id']],
            'inventory_reservations' => ['policy' => 'delete_with_stock', 'columns' => ['reference_type', 'reference_id']],
            'woocommerce_stock_tasks' => ['policy' => 'delete_with_stock', 'columns' => ['source_type', 'source_id']],
        ];
    }
}
