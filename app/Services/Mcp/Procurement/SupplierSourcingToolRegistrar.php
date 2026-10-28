<?php

namespace App\Services\Mcp\Procurement;

use App\Services\Mcp\McpExecutionContext;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP-W7 registration: suppliers, non-stock articles, quotation sourcing. A
 * tool is listed only when the connection holds its scope AND the user
 * currently holds the mapped ERP permission; every call re-checks both.
 */
final class SupplierSourcingToolRegistrar
{
    private const NOT_PROCUREMENT = 'A supplier availability request is INTERNAL tracking of a pre-sale availability check: nothing is sent to the supplier, it is not a purchase order and reserves nothing. Actual purchasing happens later on the Sales Order (procurement).';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SupplierSourcingTools $tools,
    ) {}

    /** @param \Mcp\Server\Builder $builder @param array<string, mixed> $outputSchema */
    public function register($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $page = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1];
        $perPage = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25];
        $status = ['type' => 'string', 'enum' => ['active', 'inactive']];
        $fingerprint = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];
        $idempotency = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Reuse the same key when retrying: the record is created once.'];
        $quantity = ['type' => 'string', 'pattern' => '^\\d+(?:\\.\\d{1,4})?$'];
        $nullableString = fn (int $max) => ['type' => ['string', 'null'], 'maxLength' => $max];
        $schema = fn (array $properties, array $required = []) => ['type' => 'object', ...($required === [] ? [] : ['required' => $required]),
            'properties' => $properties, 'additionalProperties' => false];
        $write = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);

        // ---------------------------------------------------------------- Suppliers
        $supplierFields = [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'contact_person' => $nullableString(255),
            'phone' => $nullableString(64),
            'email' => $nullableString(255),
            'address' => $nullableString(2000),
            'notes' => $nullableString(2000),
            'status' => $status,
        ];

        if ($this->context->allows('suppliers.view')) {
            $builder->addTool([$this->tools, 'searchSuppliers'], name: 'search_suppliers', title: 'Search suppliers',
                description: 'Search this organization\'s suppliers by name, contact person, phone or email. ALWAYS search (try partial names too) before creating a supplier, and reuse a match instead of creating a duplicate.',
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema(['query' => ['type' => 'string', 'maxLength' => 255], 'status' => $status, 'page' => $page, 'per_page' => $perPage]));
            $builder->addTool([$this->tools, 'getSupplier'], name: 'get_supplier', title: 'Get supplier',
                description: 'Return one supplier (name, contact person, phone, email, address, status, version).',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['supplier_id' => $id], ['supplier_id']));
        }

        $canCreateSupplier = $this->context->allows('suppliers.create');
        $canUpdateSupplier = $this->context->allows('suppliers.update');
        if ($canCreateSupplier || $canUpdateSupplier) {
            $builder->addTool([$this->tools, 'previewSupplierChanges'], name: 'preview_supplier_changes', title: 'Preview supplier changes',
                description: 'Required before create_supplier / update_supplier. Validates a candidate supplier with the ERP supplier rules without saving: current/proposed values, issues, possible_duplicates and a short-lived single-use preview_fingerprint. Omit supplier_id to create. Only pass details the user actually gave; never invent phone, email or contact data.',
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema(['supplier_id' => $id, 'supplier' => $schema($supplierFields)], ['supplier']));
        }
        if ($canCreateSupplier) {
            $builder->addTool([$this->tools, 'createSupplier'], name: 'create_supplier', title: 'Create supplier',
                description: 'Create exactly the supplier previewed by preview_supplier_changes (same supplier object + its preview_fingerprint). Requires an idempotency key. Use only after search_suppliers found no existing match.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema(['supplier' => $schema($supplierFields, ['name']), 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotency], ['supplier', 'preview_fingerprint', 'idempotency_key']));
        }
        if ($canUpdateSupplier) {
            $builder->addTool([$this->tools, 'updateSupplier'], name: 'update_supplier', title: 'Update supplier',
                description: 'Apply exactly the supplier changes previewed by preview_supplier_changes (same supplier_id, supplier object and preview_fingerprint). Rejected with MCP_PREVIEW_STALE if the supplier changed since the preview.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema(['supplier_id' => $id, 'supplier' => $schema($supplierFields), 'preview_fingerprint' => $fingerprint], ['supplier_id', 'supplier', 'preview_fingerprint']));
        }

        // ---------------------------------------------------------------- Non-stock articles
        $itemFields = [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'Article designation.'],
            'reference' => $nullableString(255),
            'unit_label' => $nullableString(64),
            'price_input_mode' => ['type' => 'string', 'enum' => ['ht', 'ttc'], 'description' => 'Whether unit_price is HT (default) or TTC.'],
            'unit_price' => ['type' => ['string', 'null'], 'pattern' => '^\\d+(?:\\.\\d{1,4})?$', 'description' => 'Default selling price in the chosen mode; the other side is computed by the ERP.'],
            'tax_rate_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Active tax rate of the organization (omit for no tax).'],
        ];

        if ($this->context->allows('non_stock_items.view')) {
            $builder->addTool([$this->tools, 'searchNonStockItems'], name: 'search_non_stock_items', title: 'Search non-stock articles',
                description: 'Search the reusable non-stock (external, not-in-catalog) article library by name or reference. Use it when search_products finds no catalog article, BEFORE creating a new non-stock article. These articles are never catalogue Products and have no stock.',
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema(['query' => ['type' => 'string', 'maxLength' => 255], 'status' => $status, 'page' => $page, 'per_page' => $perPage]));
            $builder->addTool([$this->tools, 'getNonStockItem'], name: 'get_non_stock_item', title: 'Get non-stock article',
                description: 'Return one non-stock article (name, reference, unit, default HT/TTC price, tax, status). Use its id as non_stock_item_id on quotation lines.',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['non_stock_item_id' => $id], ['non_stock_item_id']));
        }

        $canCreateItem = $this->context->allows('non_stock_items.create');
        $canUpdateItem = $this->context->allows('non_stock_items.update');
        if ($canCreateItem || $canUpdateItem) {
            $builder->addTool([$this->tools, 'previewNonStockItemChanges'], name: 'preview_non_stock_item_changes', title: 'Preview non-stock article changes',
                description: 'Required before create_non_stock_item / update_non_stock_item. Runs the ERP non-stock rules without saving and returns the exact proposed article (HT/TTC, tax), issues, an existing same-name article if any, possible duplicates, and a preview_fingerprint. Omit non_stock_item_id to create. Use only the name/price/tax the user supplied. Never creates a catalogue Product.',
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema(['non_stock_item_id' => $id, 'non_stock_item' => $schema([...$itemFields, 'status' => $status])], ['non_stock_item']));
        }
        if ($canCreateItem) {
            $builder->addTool([$this->tools, 'createNonStockItem'], name: 'create_non_stock_item', title: 'Create non-stock article',
                description: 'Create exactly the previewed non-stock article (same non_stock_item object + preview_fingerprint), then use its id as non_stock_item_id on a quotation line. If an article with the same name already exists the ERP returns it (reused_existing = true). Not a catalogue Product; no stock. Requires an idempotency key.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema(['non_stock_item' => $schema($itemFields, ['name']), 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotency], ['non_stock_item', 'preview_fingerprint', 'idempotency_key']));
        }
        if ($canUpdateItem) {
            $builder->addTool([$this->tools, 'updateNonStockItem'], name: 'update_non_stock_item', title: 'Update non-stock article',
                description: 'Apply exactly the previewed changes to a non-stock article. Existing quotation lines keep their own snapshots.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema(['non_stock_item_id' => $id, 'non_stock_item' => $schema([...$itemFields, 'status' => $status]), 'preview_fingerprint' => $fingerprint], ['non_stock_item_id', 'non_stock_item', 'preview_fingerprint']));
        }

        // ---------------------------------------------------------------- Quotation sourcing
        if ($this->context->allows('quotation_sourcing.view')) {
            $builder->addTool([$this->tools, 'getQuotationSourcing'], name: 'get_quotation_sourcing', title: 'Get quotation supplier sourcing',
                description: 'PREFERRED tool for "what supplier confirmations are still missing on this quotation?" and "can it be issued?". For each line of a DRAFT quotation: requested quantity, internal available stock, quantity to source, confirmed quantity, remaining quantity, readiness and the supplier availability requests (status, supplier, quantities, who/when; manual confirmations are flagged internal). Quotation-level sourcing_ready/unresolved_lines tell whether issue_quotation can pass.',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['quotation_id' => $id], ['quotation_id']));
        }
        if ($this->context->allows('quotation_sourcing.create')) {
            $builder->addTool([$this->tools, 'createSupplierAvailabilityRequest'], name: 'create_supplier_availability_request', title: 'Create supplier availability request',
                description: 'Record, for one line of a DRAFT quotation that needs sourcing, that a supplier must be asked whether it can supply. Omit requested_quantity: the ERP uses the line\'s current shortage (catalog: missing quantity only) or full quantity (non-stock article). '.self::NOT_PROCUREMENT.' The quotation stays a draft until the supplier\'s answer is recorded. Requires an idempotency key.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'quotation_id' => $id, 'quotation_line_id' => $id, 'supplier_id' => $id,
                    'requested_quantity' => [...$quantity, 'description' => 'Optional override; default is the current shortage.'],
                    'note' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Internal note (never on the customer document).'],
                    'idempotency_key' => $idempotency,
                ], ['quotation_id', 'quotation_line_id', 'supplier_id', 'idempotency_key']));
        }
        if ($this->context->allows('quotation_sourcing.update')) {
            $builder->addTool([$this->tools, 'recordSupplierAvailabilityResponse'], name: 'record_supplier_availability_response', title: 'Record supplier availability response',
                description: 'Record what the supplier ACTUALLY confirmed for a request (from get_quotation_sourcing) on a draft quotation: pass only confirmed_quantity. The ERP derives the status: at least the requested quantity → available, less → partial, 0 → unavailable. You cannot set a status and cannot create a manual confirmation (manual override is a web-only admin action). Pass expected_status (the status you read) to avoid overwriting a newer answer. Returns the updated line and quotation sourcing.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'quotation_id' => $id, 'supplier_request_id' => $id, 'confirmed_quantity' => $quantity,
                    'note' => ['type' => 'string', 'maxLength' => 2000],
                    'expected_status' => ['type' => 'string', 'enum' => ['pending', 'available', 'partial', 'unavailable']],
                ], ['quotation_id', 'supplier_request_id', 'confirmed_quantity']));
            $builder->addTool([$this->tools, 'cancelSupplierAvailabilityRequest'], name: 'cancel_supplier_availability_request', title: 'Cancel supplier availability request',
                description: 'Cancel a supplier availability request that is no longer relevant, on a draft quotation. Manual confirmations and requests of issued quotations cannot be cancelled. Returns the updated sourcing state.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'quotation_id' => $id, 'supplier_request_id' => $id,
                    'expected_status' => ['type' => 'string', 'enum' => ['pending', 'available', 'partial', 'unavailable']],
                ], ['quotation_id', 'supplier_request_id']));
        }
    }
}
