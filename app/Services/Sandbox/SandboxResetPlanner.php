<?php

namespace App\Services\Sandbox;

use App\Models\Sandbox;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 — the explicit, dependency-ordered list of Test transactional rows
 * owned by one sandbox. Shared by the preview (counts) and the executor
 * (delete order), so the two can never disagree on what a reset removes.
 *
 * Three ownership shapes exist (see the Phase 2–8 migrations):
 * - routed ledgers `sandbox_*`: every row carries `sandbox_id` (never NULL);
 * - shared roots with a nullable `sandbox_id` (NULL = Production): orders,
 *   quotations, invoice families / invoices, delivery notes, credit notes,
 *   returns, return lines, exchanges;
 * - Production line / history tables that have no `sandbox_id` and belong
 *   to exactly one Test root through their parent key (order lines,
 *   revisions, addenda, quotation lines, invoice / BL / credit-note lines).
 *
 * Every query is pinned to (organization_id, sandbox_id); a Production row
 * (`sandbox_id IS NULL`) can never match. Master data (products, variants,
 * customers, suppliers, stores, warehouses, users, roles, accounts,
 * commission rules, settings) is never listed, and `sandbox_document_sequences`
 * is deliberately kept (organization-wide monotonic Test numbering).
 *
 * The order is child → parent. Self references and the order ↔ revision
 * cycle are cleared (Test rows only) right before their table is deleted.
 */
final class SandboxResetPlanner
{
    /**
     * key => [table, domain, label, parent|null, parent column|null, nullify columns, parent nullify [table, column]|null].
     * A step with a parent selects child rows whose `parent column` points at
     * a Test row of the parent step.
     */
    private const STEPS = [
        'procurement_receipts' => ['sandbox_procurement_receipts', 'Approvisionnement', 'Réceptions fournisseur'],
        'stock_transfer_lines' => ['sandbox_stock_transfer_lines', 'Stock', 'Lignes de transfert'],
        'stock_transfers' => ['sandbox_stock_transfers', 'Stock', 'Transferts de stock'],
        'expected_collection_matches' => ['sandbox_finance_expected_collection_matches', 'Finance', 'Rapprochements d’encaissements prévus'],
        'expected_collections' => ['sandbox_finance_expected_collections', 'Finance', 'Encaissements prévus'],
        'exchange_payments' => ['sandbox_customer_exchange_payments', 'Retours & échanges', 'Paiements d’échange'],
        'payment_refunds' => ['sandbox_payment_refunds', 'Finance', 'Remboursements'],
        'payment_allocations' => ['sandbox_payment_allocations', 'Finance', 'Affectations de paiement'],
        'payments' => ['sandbox_payments', 'Finance', 'Paiements'],
        'commission_entries' => ['sandbox_commission_entries', 'Finance', 'Commissions', null, null, ['source_entry_id']],
        'fulfillment_notifications' => ['sandbox_fulfillment_customer_notifications', 'Expéditions', 'Notifications client'],
        'fulfillment_attachments' => ['sandbox_fulfillment_attachments', 'Expéditions', 'Pièces jointes d’expédition'],
        'fulfillment_items' => ['sandbox_fulfillment_items', 'Expéditions', 'Articles expédiés'],
        'fulfillments' => ['sandbox_fulfillments', 'Expéditions', 'Expéditions'],
        'purchase_order_lines' => ['sandbox_purchase_order_lines', 'Approvisionnement', 'Lignes de bon de commande'],
        'purchase_orders' => ['sandbox_purchase_orders', 'Approvisionnement', 'Bons de commande fournisseur'],
        'procurements' => ['sandbox_sales_order_procurements', 'Approvisionnement', 'Approvisionnements'],
        'availability_requests' => ['sandbox_quotation_supplier_availability_requests', 'Approvisionnement', 'Demandes de disponibilité'],
        'inventory_allocations' => ['sandbox_sales_order_inventory_allocations', 'Stock', 'Allocations de stock'],
        'inventory_reservations' => ['sandbox_inventory_reservations', 'Stock', 'Réservations de stock'],
        'inventory_movements' => ['sandbox_inventory_movements', 'Stock', 'Mouvements de stock'],
        'inventory_balances' => ['sandbox_inventory_balances', 'Stock', 'Soldes de stock'],
        'credit_note_lines' => ['credit_note_lines', 'Documents', 'Lignes d’avoir', 'credit_notes', 'credit_note_id'],
        'credit_notes' => ['credit_notes', 'Documents', 'Avoirs'],
        'exchanges' => ['customer_exchanges', 'Retours & échanges', 'Échanges'],
        'return_lines' => ['customer_return_lines', 'Retours & échanges', 'Lignes de retour'],
        'returns' => ['customer_returns', 'Retours & échanges', 'Retours'],
        'delivery_note_lines' => ['delivery_note_lines', 'Documents', 'Lignes de bon de livraison', 'delivery_notes', 'delivery_note_id'],
        'delivery_notes' => ['delivery_notes', 'Documents', 'Bons de livraison'],
        'invoice_lines' => ['invoice_lines', 'Documents', 'Lignes de facture', 'invoices', 'invoice_id'],
        'invoices' => ['invoices', 'Documents', 'Factures', null, null, ['corrected_invoice_id']],
        'invoice_families' => ['invoice_families', 'Documents', 'Familles de factures'],
        'quotation_lines' => ['quotation_lines', 'Ventes', 'Lignes de devis', 'quotations', 'quotation_id'],
        'quotations' => ['quotations', 'Ventes', 'Devis', null, null, ['revised_from_quotation_id', 'root_quotation_id']],
        'sales_order_lines' => ['sales_order_lines', 'Ventes', 'Lignes de commande', 'sales_orders', 'sales_order_id'],
        'sales_order_addenda' => ['sales_order_addenda', 'Ventes', 'Avenants de commande', 'sales_orders', 'sales_order_id'],
        'sales_order_revisions' => ['sales_order_revisions', 'Ventes', 'Révisions de commande', 'sales_orders', 'sales_order_id', [], ['sales_orders', 'current_revision_id']],
        'sales_orders' => ['sales_orders', 'Ventes', 'Commandes'],
    ];

    /** @return list<array{key:string,table:string,domain:string,label:string,parent:?string,parent_column:?string,nullify:list<string>,parent_nullify:?array{0:string,1:string}}> */
    public function steps(): array
    {
        $out = [];
        foreach (self::STEPS as $key => $step) {
            $out[] = [
                'key' => $key, 'table' => $step[0], 'domain' => $step[1], 'label' => $step[2],
                'parent' => $step[3] ?? null, 'parent_column' => $step[4] ?? null,
                'nullify' => $step[5] ?? [], 'parent_nullify' => $step[6] ?? null,
            ];
        }

        return $out;
    }

    /** @return list<string> every table the reset touches */
    public function tables(): array
    {
        return array_values(array_unique(array_column(self::STEPS, 0)));
    }

    /** Test rows of one step, pinned to the sandbox and its organization. */
    public function query(Sandbox $sandbox, array $step): Builder
    {
        $query = DB::table($step['table'])->where($step['table'].'.organization_id', $sandbox->organization_id);
        if ($step['parent'] === null) {
            return $query->where($step['table'].'.sandbox_id', $sandbox->getKey());
        }

        return $query->whereIn($step['table'].'.'.$step['parent_column'], DB::table($step['parent'])->select('id')
            ->where('organization_id', $sandbox->organization_id)
            ->where('sandbox_id', $sandbox->getKey()));
    }

    /** @return array<string,int> key => Test row count */
    public function counts(Sandbox $sandbox): array
    {
        $counts = [];
        foreach ($this->steps() as $step) {
            $counts[$step['key']] = $this->query($sandbox, $step)->count();
        }

        return $counts;
    }

    /**
     * Preview grouped by domain, plus a fingerprint binding the confirmation
     * to exactly this sandbox generation and these counts.
     *
     * @return array{sandbox:array{id:int,name:string,generation:int,status:string},domains:list<array{domain:string,total:int,items:list<array{key:string,label:string,count:int}>}>,total:int,fingerprint:string}
     */
    public function preview(Sandbox $sandbox): array
    {
        $counts = $this->counts($sandbox);
        $domains = [];
        foreach ($this->steps() as $step) {
            $domains[$step['domain']]['domain'] = $step['domain'];
            $domains[$step['domain']]['items'][] = ['key' => $step['key'], 'label' => $step['label'], 'count' => $counts[$step['key']]];
            $domains[$step['domain']]['total'] = ($domains[$step['domain']]['total'] ?? 0) + $counts[$step['key']];
        }
        $order = ['Ventes', 'Documents', 'Finance', 'Stock', 'Approvisionnement', 'Expéditions', 'Retours & échanges'];
        uksort($domains, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        return [
            'sandbox' => ['id' => (int) $sandbox->getKey(), 'name' => (string) $sandbox->name, 'generation' => (int) $sandbox->generation, 'status' => (string) $sandbox->status],
            'domains' => array_values(array_map(fn (array $d) => ['domain' => $d['domain'], 'total' => $d['total'], 'items' => array_reverse($d['items'])], $domains)),
            'total' => array_sum($counts),
            'fingerprint' => $this->fingerprint($sandbox, $counts),
        ];
    }

    /** @param array<string,int> $counts */
    public function fingerprint(Sandbox $sandbox, array $counts): string
    {
        return hash('sha256', json_encode([
            'organization' => (int) $sandbox->organization_id, 'sandbox' => (int) $sandbox->getKey(),
            'generation' => (int) $sandbox->generation, 'counts' => $counts,
        ], JSON_THROW_ON_ERROR));
    }
}
