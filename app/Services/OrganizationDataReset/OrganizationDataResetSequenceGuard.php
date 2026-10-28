<?php

namespace App\Services\OrganizationDataReset;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrganizationDataResetSequenceGuard
{
    /** @var list<string> */
    private const SEQUENCE_TABLES = [
        'sales_order_sequences', 'payment_sequences', 'payment_refund_sequences',
        'invoice_sequences', 'delivery_note_sequences', 'quotation_sequences',
        'stock_transfer_sequences', 'transfer_request_sequences', 'procurement_sequences', 'purchase_order_sequences',
        'customer_return_sequences', 'credit_note_sequences', 'customer_exchange_sequences',
        'finance_obligation_sequences', 'finance_outgoing_payment_sequences',
        'finance_recurring_template_sequences', 'finance_supplier_plan_sequences',
        'finance_instrument_sequences',
    ];

    /** @var list<string> */
    private const PROTECTED_CONFIGURATION = [
        'organizations', 'stores', 'warehouses', 'financial_accounts', 'tax_rates', 'units_of_measure',
        'organization_mail_settings', 'organization_security_settings', 'organization_document_stamps',
        'organization_backup_settings', 'organization_backups', 'organization_cloud_backup_connections',
        'organization_backup_cloud_copies', 'woocommerce_integrations',
        'finance_recurring_obligation_templates', 'commission_rule_sets', 'commission_rule_tiers',
        'audit_logs', 'document_email_deliveries',
    ];

    /** @param list<OrganizationDataResetOperation> $operations */
    public function assertOperationsSafe(array $operations): void
    {
        foreach ($operations as $operation) {
            $isApprovedFullResetUnlink = $operation->key === 'full_organization.unlink_recurring_template_supplier'
                && $operation->scope === OrganizationDataResetScope::FullOrganization
                && $operation->table === 'finance_recurring_obligation_templates'
                && $operation->action === OrganizationDataResetOperation::SET_NULL
                && $operation->column === 'supplier_id';

            if (in_array($operation->table, self::SEQUENCE_TABLES, true)
                || (in_array($operation->table, self::PROTECTED_CONFIGURATION, true) && ! $isApprovedFullResetUnlink)
                || str_ends_with($operation->table, '_sequences')) {
                throw new OrganizationDataResetException("Reset operation targets protected sequence/configuration table [{$operation->table}].");
            }

            if (Schema::hasTable($operation->table)
                && $operation->action !== OrganizationDataResetOperation::CLEAR_NON_OWNER_ACTIVE_CONTEXT
                && ! Schema::hasColumn($operation->table, 'organization_id')) {
                throw new OrganizationDataResetException("Reset operation is not tenant-scoped [{$operation->table}].");
            }
        }
    }

    /** @return array<string,mixed> */
    public function snapshot(Organization $organization): array
    {
        $snapshot = [
            'organization_settings' => DB::table('organizations')->where('id', $organization->getKey())->value('settings'),
        ];

        foreach (self::SEQUENCE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $snapshot[$table] = DB::table($table)
                ->where('organization_id', $organization->getKey())
                ->orderBy('organization_id')
                ->when(Schema::hasColumn($table, 'year'), fn ($query) => $query->orderBy('year'))
                ->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $before */
    public function assertUnchanged(Organization $organization, array $before): void
    {
        if ($before !== $this->snapshot($organization)) {
            throw new OrganizationDataResetException('Document/transaction numbering or organization settings changed during reset.');
        }
    }

    /** @return list<string> */
    public function protectedTables(): array
    {
        return array_values(array_unique(array_merge(self::SEQUENCE_TABLES, self::PROTECTED_CONFIGURATION)));
    }
}
