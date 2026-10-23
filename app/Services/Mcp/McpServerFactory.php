<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Products\McpProductChangeService;
use App\Services\Mcp\Products\ProductOperationTools;
use App\Services\Mcp\Products\ProductReadTools;
use App\Services\Mcp\Products\ProductWriteTools;
use App\Services\Mcp\Sales\SalesAssistantTools;
use App\Services\Mcp\Sales\QuotationLifecycleTools;
use App\Services\Mcp\Sales\SalesOrderTools;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;

final class McpServerFactory
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly ProductReadTools $productTools,
        private readonly ProductWriteTools $productWriteTools,
        private readonly ProductOperationTools $operationTools,
        private readonly SalesAssistantTools $salesTools,
        private readonly SalesOrderTools $salesOrderTools,
        private readonly QuotationLifecycleTools $quotationLifecycleTools,
    ) {}

    public function make(): Server
    {
        $directory = storage_path('framework/mcp-sessions/'.$this->context->connectionOrFail()->getKey());

        $builder = Server::builder()
            ->setServerInfo('10xScale ERP', '1.0.0', 'Tenant-scoped 10xScale ERP capabilities')
            ->setSession(new FileSessionStore($directory, (int) config('mcp.session_ttl_seconds', 3600)))
            ->setSessionLock(new FileSessionLock($directory));

        $readOnly = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $outputSchema = [
            'type' => 'object',
            'required' => ['ok'],
            'properties' => [
                'ok' => ['type' => 'boolean'],
                'data' => ['type' => 'object'],
                'error' => [
                    'type' => 'object',
                    'required' => ['code', 'message'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'details' => ['type' => 'object'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ];

        if ($this->context->allows('products.view')) {
            $builder->addTool(
                [$this->productTools, 'searchProducts'],
                name: 'search_products',
                title: 'Search products',
                description: 'Search this connection organization\'s products by name, SKU, reference, or barcode using bounded deterministic pagination.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'maxLength' => 255],
                        'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                        'brand_id' => ['type' => 'integer', 'minimum' => 1],
                        'category_id' => ['type' => 'integer', 'minimum' => 1],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->productTools, 'getProduct'],
                name: 'get_product',
                title: 'Get product',
                description: 'Return an organization-scoped product with variants, identifiers, status, channel metadata, and resolved HT/TTC tax pricing.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'required' => ['product_id'],
                    'properties' => ['product_id' => ['type' => 'integer', 'minimum' => 1]],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->productTools, 'getProductVariant'],
                name: 'get_product_variant',
                title: 'Get product variant',
                description: 'Return an organization-scoped variant with identifiers, status, channel metadata, and resolved HT/TTC tax pricing.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'required' => ['variant_id'],
                    'properties' => ['variant_id' => ['type' => 'integer', 'minimum' => 1]],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );

            if ($this->context->allows('inventory.view')) {
                $builder->addTool(
                    [$this->productTools, 'getProductStock'],
                    name: 'get_product_stock',
                    title: 'Get product stock',
                    description: 'Return exact organization-wide on-hand, reserved, and available stock totals and warehouse breakdowns for one product or variant.',
                    annotations: $readOnly,
                    inputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'minimum' => 1],
                            'variant_id' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'oneOf' => [
                            ['required' => ['product_id']],
                            ['required' => ['variant_id']],
                        ],
                        'additionalProperties' => false,
                    ],
                    outputSchema: $outputSchema,
                );

                if ($this->context->allows('product_cost.view')) {
                    $builder->addTool(
                        [$this->productTools, 'getPurchaseCostCoverage'],
                        name: 'get_purchase_cost_coverage',
                        title: 'Get purchase cost coverage of in-stock variants',
                        description: 'PREFERRED tool for purchase-cost completeness questions, e.g. "Show me all products currently available in stock that are missing a purchase price" '
                            .'or "what share of in-stock variants have a cost?". One call returns, at variant level, a summary over ALL matching in-stock variants '
                            .'(total, with/without purchase cost, coverage_percent) plus a paginated list with SKU, reference, barcode, purchase_cost (null = missing; 0.0000 is a recorded cost) '
                            .'and organization-wide stock. In stock means available > 0 by default (stock_basis "on_hand" uses on-hand quantity). '
                            .'Use purchase_cost_status "without_cost" to list only the gaps. Do not loop over search_products/get_product_stock for this.',
                        annotations: $readOnly,
                        inputSchema: [
                            'type' => 'object',
                            'properties' => [
                                'stock_basis' => ['type' => 'string', 'enum' => ['available', 'on_hand'], 'default' => 'available'],
                                'status' => ['type' => 'string', 'enum' => ['active', 'inactive'], 'description' => 'Variant status filter.'],
                                'purchase_cost_status' => ['type' => 'string', 'enum' => ['all', 'with_cost', 'without_cost'], 'default' => 'all', 'description' => 'Filters the items list only; the summary always covers all matching in-stock variants.'],
                                'query' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Matches product name, SKU, reference, or barcode.'],
                                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                            ],
                            'additionalProperties' => false,
                        ],
                        outputSchema: $outputSchema,
                    );
                }
            }
        }

        $this->registerProductWriteTools($builder, $readOnly, $outputSchema);
        $this->registerProductOperationTools($builder, $readOnly, $outputSchema);
        $this->registerSalesTools($builder, $readOnly, $outputSchema);
        $this->registerSalesOrderTools($builder, $readOnly, $outputSchema);
        $this->registerQuotationLifecycleTools($builder, $readOnly, $outputSchema);

        return $builder->build();
    }

    /** @param array<string, mixed> $outputSchema */
    private function registerQuotationLifecycleTools($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $byId = ['type' => 'object', 'required' => ['quotation_id'], 'properties' => ['quotation_id' => ['type' => 'integer', 'minimum' => 1]], 'additionalProperties' => false];
        $withFingerprint = fn (array $extra = []) => [
            'type' => 'object', 'required' => ['quotation_id', 'preview_fingerprint', ...array_keys($extra)],
            'properties' => ['quotation_id' => ['type' => 'integer', 'minimum' => 1], 'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'], ...$extra],
            'additionalProperties' => false,
        ];
        $transition = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);

        if ($this->context->allows('quotations.issue')) {
            $builder->addTool([$this->quotationLifecycleTools, 'previewQuotationIssue'],
                name: 'preview_quotation_issue', title: 'Preview quotation issue',
                description: 'Required before issue_quotation. Checks with the ERP issue rules (draft, has lines, validity date, seller profile) whether a draft quotation can be issued, without saving anything. Returns issues and a short-lived single-use preview_fingerprint.',
                annotations: $readOnly, inputSchema: $byId, outputSchema: $outputSchema);
            $builder->addTool([$this->quotationLifecycleTools, 'issueQuotation'],
                name: 'issue_quotation', title: 'Issue quotation',
                description: 'Issue a draft quotation (allocates its official number, freezes it) with the fingerprint from preview_quotation_issue. Does not accept or convert. Retrying with the same fingerprint after success returns the issued quotation.',
                annotations: $transition, inputSchema: $withFingerprint(), outputSchema: $outputSchema);
        }

        if ($this->context->allows('quotations.accept')) {
            $builder->addTool([$this->quotationLifecycleTools, 'previewQuotationAcceptance'],
                name: 'preview_quotation_acceptance', title: 'Preview quotation acceptance',
                description: 'Required before accept_quotation. Checks with the ERP rules whether an issued quotation can be marked accepted, without saving anything. Returns issues and a short-lived single-use preview_fingerprint.',
                annotations: $readOnly, inputSchema: $byId, outputSchema: $outputSchema);
            $builder->addTool([$this->quotationLifecycleTools, 'acceptQuotation'],
                name: 'accept_quotation', title: 'Accept quotation',
                description: 'Record the customer\'s acceptance of an issued quotation with the fingerprint from preview_quotation_acceptance. Does not convert it or create a Sales Order.',
                annotations: $transition, inputSchema: $withFingerprint(), outputSchema: $outputSchema);
        }

        if ($this->context->allows('quotations.convert')) {
            $builder->addTool([$this->quotationLifecycleTools, 'previewQuotationConversion'],
                name: 'preview_quotation_conversion', title: 'Preview quotation conversion',
                description: 'Required before convert_quotation_to_sales_order. Runs the ERP conversion without saving anything and returns the draft Sales Order it would create (customer, lines, prices, taxes, totals), stock warnings, issues, and a short-lived single-use preview_fingerprint. An already converted quotation reports its existing Sales Order.',
                annotations: $readOnly, inputSchema: $byId, outputSchema: $outputSchema);
            $builder->addTool([$this->quotationLifecycleTools, 'convertQuotationToSalesOrder'],
                name: 'convert_quotation_to_sales_order', title: 'Convert quotation to sales order',
                description: 'Convert an issued or accepted quotation into ONE draft Sales Order built from the quotation itself (no client-supplied lines or prices). Requires the conversion preview fingerprint and an idempotency key. Never confirms the order, reserves stock, issues an invoice or records a payment; a quotation is never converted twice.',
                annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
                inputSchema: $withFingerprint(['idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]]),
                outputSchema: $outputSchema);
        }
    }

    /** @param array<string, mixed> $outputSchema */
    private function registerSalesOrderTools($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $date = ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
        $fingerprint = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];

        if ($this->context->allows('sales_orders.view')) {
            $builder->addTool(
                [$this->salesOrderTools, 'searchSalesOrders'],
                name: 'search_sales_orders', title: 'Search sales orders',
                description: 'Search Sales Orders in the stores the connection user belongs to, by order number or customer snapshot (name, company, phone, email). Newest first. Includes payment status and paid/remaining amounts.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'maxLength' => 255],
                        'status' => ['type' => 'string', 'enum' => ['draft', 'confirmed', 'cancelled']],
                        'payment_status' => ['type' => 'string', 'enum' => ['unpaid', 'partially_paid', 'paid']],
                        'customer_id' => $id,
                        'store_id' => $id,
                        'date_from' => [...$date, 'description' => 'Sale date lower bound (inclusive).'],
                        'date_to' => [...$date, 'description' => 'Sale date upper bound (inclusive).'],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->salesOrderTools, 'getSalesOrder'],
                name: 'get_sales_order', title: 'Get sales order',
                description: 'Return one Sales Order: header, customer snapshot, priced lines (HT/TTC, discounts, taxes), totals, status, editable flag, confirmation, payment summary, version token. Stock allocations/reservations are included when inventory.view is granted.',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'required' => ['sales_order_id'], 'properties' => ['sales_order_id' => $id], 'additionalProperties' => false],
                outputSchema: $outputSchema,
            );
        }

        $canCreate = $this->context->allows('sales_orders.create');
        $canUpdate = $this->context->allows('sales_orders.update');
        $mutation = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);
        $decimal = ['type' => ['string', 'null'], 'pattern' => '^\\d+(?:\\.\\d{1,4})?$'];
        $item = [
            'type' => 'object',
            'properties' => [
                'line_id' => [...$id, 'description' => 'Update only: existing line to keep/modify. Omitted fields keep the line\'s values, except an omitted catalog unit_price, which means the current catalog price. Lines not listed are removed.'],
                'variant_id' => [...$id, 'description' => 'Catalog product variant (see search_products).'],
                'description' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Custom (non-catalog) line label. Use instead of variant_id.'],
                'reference' => ['type' => ['string', 'null'], 'maxLength' => 255, 'description' => 'Custom lines only.'],
                'unit_label' => ['type' => ['string', 'null'], 'maxLength' => 255, 'description' => 'Custom lines only.'],
                'tax_rate_id' => [...$id, 'description' => 'Custom lines, or a catalog line whose tax is unresolved.'],
                'quantity' => ['type' => 'string', 'pattern' => '^[1-9]\\d*(?:\\.0{1,4})?$'],
                'price_input_mode' => ['type' => 'string', 'enum' => ['ht', 'ttc'], 'description' => 'Custom lines: HT or TTC entry. Catalog overrides are HT only.'],
                'unit_price' => [...$decimal, 'description' => 'Catalog: HT override (needs the ERP price-override permission). Custom: the line price.'],
                'discount_type' => ['type' => 'string', 'enum' => ['none', 'fixed', 'percentage'], 'description' => 'Discounts need the ERP apply-discount permission.'],
                'discount_value' => $decimal,
            ],
            'additionalProperties' => false,
        ];
        $header = [
            'customer_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'order_date' => [...$date, 'description' => 'Sale date; defaults to today on create.'],
            'notes' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => (int) config('mcp.sales_orders.max_items', 200), 'items' => $item],
        ];
        $createOrder = ['type' => 'object', 'properties' => ['store_id' => [...$id, 'description' => 'Required when the user belongs to several stores.'], ...$header], 'additionalProperties' => false];
        $updateOrder = ['type' => 'object', 'minProperties' => 1, 'properties' => $header, 'additionalProperties' => false];

        if ($canCreate || $canUpdate) {
            $builder->addTool(
                [$this->salesOrderTools, 'previewSalesOrder'],
                name: 'preview_sales_order', title: 'Preview sales order draft',
                description: 'Validate and price a candidate Sales Order draft with the ERP\'s own rules, without saving anything: resolved customer, lines (HT/TTC, discounts, taxes), totals, validation issues, informational stock. '
                    .'Omit sales_order_id for a new draft; pass it to preview changes to an existing draft. Issues a short-lived single-use preview_fingerprint when valid. Never confirms, reserves, invoices or records payments.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object', 'required' => ['sales_order'],
                    'properties' => ['sales_order_id' => $id, 'sales_order' => ['type' => 'object', 'properties' => $createOrder['properties'], 'additionalProperties' => false]],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }
        if ($canCreate) {
            $builder->addTool(
                [$this->salesOrderTools, 'createSalesOrderDraft'],
                name: 'create_sales_order_draft', title: 'Create sales order draft',
                description: 'Create a DRAFT Sales Order exactly as previewed (same sales_order object and its preview_fingerprint). Requires an idempotency key. Does not confirm, invoice or record payments.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['sales_order', 'preview_fingerprint', 'idempotency_key'],
                    'properties' => [
                        'sales_order' => $createOrder,
                        'preview_fingerprint' => $fingerprint,
                        'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }
        if ($canUpdate) {
            $builder->addTool(
                [$this->salesOrderTools, 'updateSalesOrderDraft'],
                name: 'update_sales_order_draft', title: 'Update sales order draft',
                description: 'Apply previewed changes to a draft Sales Order (same sales_order_id and sales_order object as the preview). If items is given it is the complete desired line list. Rejected when the draft changed or is no longer editable.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['sales_order_id', 'sales_order', 'preview_fingerprint'],
                    'properties' => ['sales_order_id' => $id, 'sales_order' => $updateOrder, 'preview_fingerprint' => $fingerprint],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('sales_orders.confirm')) {
            $builder->addTool(
                [$this->salesOrderTools, 'previewSalesOrderConfirmation'],
                name: 'preview_sales_order_confirmation', title: 'Preview sales order confirmation',
                description: 'Required before confirm_sales_order. Runs the ERP confirmation checks (lines, taxes, stock coverage, supplier procurements) without committing anything and returns the stock reservation plan, issues, and a short-lived single-use preview_fingerprint.',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'required' => ['sales_order_id'], 'properties' => ['sales_order_id' => $id], 'additionalProperties' => false],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->salesOrderTools, 'confirmSalesOrder'],
                name: 'confirm_sales_order', title: 'Confirm sales order',
                description: 'Confirm a draft Sales Order with the fingerprint from preview_sales_order_confirmation. Reserves company stock and makes the order an official commitment; everything is re-checked at execution. Does NOT issue an invoice or record a payment. Retrying with the same fingerprint after success returns the confirmed order.',
                annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
                inputSchema: [
                    'type' => 'object', 'required' => ['sales_order_id', 'preview_fingerprint'],
                    'properties' => ['sales_order_id' => $id, 'preview_fingerprint' => $fingerprint],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }
    }

    /** @param array<string, mixed> $outputSchema */
    private function registerSalesTools($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $page = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1];
        $perPage = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25];
        $date = ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];

        if ($this->context->allows('customers.view')) {
            $builder->addTool(
                [$this->salesTools, 'searchCustomers'],
                name: 'search_customers', title: 'Search customers',
                description: 'Search this organization\'s customers by name, company, email, phone, or WhatsApp username with bounded deterministic pagination (display name, then id).',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'maxLength' => 255],
                        'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                        'page' => $page,
                        'per_page' => $perPage,
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->salesTools, 'getCustomer'],
                name: 'get_customer', title: 'Get customer',
                description: 'Return one customer\'s identity, contact and billing data. Internal notes are not exposed.',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'required' => ['customer_id'], 'properties' => ['customer_id' => $id], 'additionalProperties' => false],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->salesTools, 'getCustomerBalanceSummary'],
                name: 'get_customer_balance_summary', title: 'Get customer balance summary',
                description: 'Read-only sales/receivable summary shown on the ERP customer profile: confirmed sales, issued invoices, posted payments, outstanding, last activity. Decimal strings.',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'required' => ['customer_id'], 'properties' => ['customer_id' => $id], 'additionalProperties' => false],
                outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('quotations.view')) {
            $builder->addTool(
                [$this->salesTools, 'searchQuotations'],
                name: 'search_quotations', title: 'Search quotations',
                description: 'Search quotations (devis) in the stores the connection user belongs to, by number or customer snapshot, newest first.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'maxLength' => 255],
                        'status' => ['type' => 'string', 'enum' => ['draft', 'issued', 'accepted', 'rejected', 'expired', 'converted', 'superseded']],
                        'customer_id' => $id,
                        'store_id' => $id,
                        'date_from' => $date,
                        'date_to' => $date,
                        'page' => $page,
                        'per_page' => $perPage,
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->salesTools, 'getQuotation'],
                name: 'get_quotation', title: 'Get quotation',
                description: 'Return one quotation with header, customer snapshot, priced lines, totals, and a version token.',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'required' => ['quotation_id'], 'properties' => ['quotation_id' => $id], 'additionalProperties' => false],
                outputSchema: $outputSchema,
            );
        }

        $canCreate = $this->context->allows('quotations.create');
        $canUpdate = $this->context->allows('quotations.update');
        if (! $canCreate && ! $canUpdate) return;

        $mutation = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);
        $decimal = ['type' => ['string', 'null'], 'pattern' => '^\\d+(?:\\.\\d{1,4})?$'];
        $item = [
            'type' => 'object',
            'properties' => [
                'line_id' => [...$id, 'description' => 'Update only: existing line to keep/modify. Omitted fields keep the line\'s current values. Lines not listed are removed.'],
                'variant_id' => [...$id, 'description' => 'Catalog product variant (see search_products).'],
                'non_stock_item_id' => [...$id, 'description' => 'Existing non-stock library article.'],
                'quantity' => ['type' => 'string', 'pattern' => '^[1-9]\\d*(?:\\.0{1,4})?$'],
                'price_input_mode' => ['type' => 'string', 'enum' => ['ht', 'ttc']],
                'unit_price' => [...$decimal, 'description' => 'Optional unit price override in price_input_mode; omitted = catalog default.'],
                'discount_type' => ['type' => 'string', 'enum' => ['none', 'fixed', 'percentage']],
                'discount_value' => $decimal,
            ],
            'additionalProperties' => false,
        ];
        $max = (int) config('mcp.quotations.max_items', 200);
        $header = [
            'customer_id' => $id,
            'quotation_date' => $date,
            'valid_until' => ['type' => ['string', 'null'], 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'notes' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            'terms' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $max, 'items' => $item],
        ];
        $createQuotation = ['type' => 'object', 'properties' => ['store_id' => [...$id, 'description' => 'Required when the user belongs to several stores.'], ...$header], 'additionalProperties' => false];
        $updateQuotation = ['type' => 'object', 'minProperties' => 1, 'properties' => $header, 'additionalProperties' => false];
        $fingerprint = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];

        $builder->addTool(
            [$this->salesTools, 'previewQuotation'],
            name: 'preview_quotation', title: 'Preview quotation draft',
            description: 'Validate and price a candidate quotation draft without mutation: resolved customer and items, HT/TTC unit prices, discounts, taxes and totals, validation issues, and informational stock. '
                .'Omit quotation_id to preview a new draft; pass it to preview changes to an existing draft (current vs proposed). Issues a short-lived single-use preview_fingerprint when valid.',
            annotations: $readOnly,
            inputSchema: [
                'type' => 'object', 'required' => ['quotation'],
                'properties' => ['quotation_id' => $id, 'quotation' => ['type' => 'object', 'properties' => $createQuotation['properties'], 'additionalProperties' => false]],
                'additionalProperties' => false,
            ],
            outputSchema: $outputSchema,
        );

        if ($canCreate) {
            $builder->addTool(
                [$this->salesTools, 'createQuotationDraft'],
                name: 'create_quotation_draft', title: 'Create quotation draft',
                description: 'Create a DRAFT quotation exactly as previewed (same quotation object and its preview_fingerprint). Never issues, accepts, converts, or reserves stock. Requires an idempotency key.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['quotation', 'preview_fingerprint', 'idempotency_key'],
                    'properties' => [
                        'quotation' => [...$createQuotation, 'required' => ['customer_id', 'items']],
                        'preview_fingerprint' => $fingerprint,
                        'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }

        if ($canUpdate) {
            $builder->addTool(
                [$this->salesTools, 'updateQuotationDraft'],
                name: 'update_quotation_draft', title: 'Update quotation draft',
                description: 'Apply previewed changes to a draft quotation (same quotation_id and quotation object as the preview). If items is given it is the complete desired line list. Rejected when the draft changed or is no longer editable.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['quotation_id', 'quotation', 'preview_fingerprint'],
                    'properties' => ['quotation_id' => $id, 'quotation' => $updateQuotation, 'preview_fingerprint' => $fingerprint],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }
    }

    /** @param array<string, mixed> $outputSchema */
    private function registerProductWriteTools($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $mutation = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);
        $archive = new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false);
        $productFields = $this->productFieldSchema();
        $variantFields = $this->variantFieldSchema();
        $candidateFields = array_replace($productFields, $variantFields, [
            'variant' => ['type' => 'object', 'properties' => $variantFields, 'additionalProperties' => false],
        ]);

        if ($this->context->allows('products.view')) {
            $builder->addTool(
                [$this->productWriteTools, 'validateProductData'],
                name: 'validate_product_data',
                title: 'Validate product data',
                description: 'Validate and normalize candidate product or variant data without mutation.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'required' => ['entity_type', 'mode', 'data'],
                    'properties' => [
                        'entity_type' => ['type' => 'string', 'enum' => ['product', 'variant']],
                        'mode' => ['type' => 'string', 'enum' => ['create', 'update']],
                        'target_id' => ['type' => 'integer', 'minimum' => 1],
                        'data' => ['type' => 'object', 'properties' => $candidateFields, 'additionalProperties' => false],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.update') || $this->context->allows('products.archive') || $this->context->allows('product_cost.manage')) {
            $builder->addTool(
                [$this->productWriteTools, 'previewProductChanges'],
                name: 'preview_product_changes',
                title: 'Preview product changes',
                description: 'Preview one product, variant, purchase-cost, or archive mutation and issue a short-lived fingerprint when safe.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'required' => ['target_type', 'target_id'],
                    'properties' => [
                        'target_type' => ['type' => 'string', 'enum' => ['product', 'variant', 'purchase_price', 'archive_product']],
                        'target_id' => ['type' => 'integer', 'minimum' => 1],
                        'changes' => ['type' => 'object', 'properties' => array_replace($productFields, $variantFields), 'additionalProperties' => false, 'default' => []],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.create')) {
            $builder->addTool(
                [$this->productWriteTools, 'createProduct'],
                name: 'create_product', title: 'Create product',
                description: 'Create one product and its initial variant without creating stock. Requires an idempotency key.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['data', 'idempotency_key'],
                    'properties' => [
                        'data' => ['type' => 'object', 'properties' => [...$productFields, 'variant' => ['type' => 'object', 'properties' => $variantFields, 'additionalProperties' => false]], 'additionalProperties' => false],
                        'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->productWriteTools, 'createVariant'],
                name: 'create_variant', title: 'Create product variant',
                description: 'Create one variant under an organization-scoped product without creating stock. Requires an idempotency key.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['product_id', 'data', 'idempotency_key'],
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'minimum' => 1],
                        'data' => ['type' => 'object', 'properties' => $variantFields, 'additionalProperties' => false],
                        'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.update')) {
            $builder->addTool(
                [$this->productWriteTools, 'updateProduct'],
                name: 'update_product', title: 'Update product',
                description: 'Apply explicit product fields after a matching short-lived preview.',
                annotations: $mutation,
                inputSchema: $this->updateSchema('product_id', $productFields), outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->productWriteTools, 'updateVariant'],
                name: 'update_variant', title: 'Update product variant',
                description: 'Apply explicit non-cost variant fields after a matching short-lived preview.',
                annotations: $mutation,
                inputSchema: $this->updateSchema('variant_id', array_diff_key($variantFields, ['purchase_price' => true])), outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('product_cost.manage')) {
            $builder->addTool(
                [$this->productWriteTools, 'updateProductPurchasePrice'],
                name: 'update_product_purchase_price', title: 'Update product purchase price',
                description: 'Update one variant purchase cost using an exact decimal and a matching preview.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['variant_id', 'purchase_price', 'preview_fingerprint'],
                    'properties' => [
                        'variant_id' => ['type' => 'integer', 'minimum' => 1],
                        'purchase_price' => ['type' => 'string', 'pattern' => '^\\d+(?:\\.\\d{1,4})?$'],
                        'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.archive')) {
            $builder->addTool(
                [$this->productWriteTools, 'archiveProduct'],
                name: 'archive_product', title: 'Archive product',
                description: 'Deactivate a product and its variants after an explicit archive preview. No rows are deleted.',
                annotations: $archive,
                inputSchema: [
                    'type' => 'object', 'required' => ['product_id', 'preview_fingerprint'],
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'minimum' => 1],
                        'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }
    }

    /** @param array<string, mixed> $outputSchema */
    private function registerProductOperationTools($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        if (! $this->context->allows('products.view')) return;

        $prepare = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);
        $execute = new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false);
        $max = (int) config('mcp.operations.max_items', 5000);
        $key = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];
        // Row schemas describe structure only; row-level problems (bad decimals,
        // unknown fields, missing targets) are reported per row in the preview
        // instead of rejecting the whole request.
        $rows = fn (array $properties) => ['type' => 'array', 'minItems' => 1, 'maxItems' => $max, 'items' => ['type' => 'object', 'properties' => $properties]];
        $locator = fn (string $idField) => [$idField => ['type' => 'integer'], 'sku' => ['type' => 'string']];
        $prepareDescription = ' Validates every row and returns preview counts without mutating anything. Up to '.$max.' rows. '
            .'Small requests return the preview immediately; large ones return status "validating" (poll get_product_operation until "previewed"). '
            .'The same idempotency_key with the same rows returns the existing operation.';
        $operationId = ['type' => 'string', 'pattern' => '^[0-9A-Za-z]{26}$'];

        if ($this->context->allows('products.update')) {
            $builder->addTool(
                [$this->operationTools, 'prepareProductBulkUpdate'],
                name: 'prepare_product_bulk_update', title: 'Prepare product bulk update',
                description: 'Prepare explicit product or variant field changes (no purchase cost, no stock). Each row targets a product or variant by target_id or sku.'.$prepareDescription,
                annotations: $prepare,
                inputSchema: [
                    'type' => 'object', 'required' => ['rows', 'idempotency_key'],
                    'properties' => [
                        'rows' => $rows([
                            'target_type' => ['type' => 'string', 'enum' => ['product', 'variant']],
                            ...$locator('target_id'),
                            'changes' => [
                                'type' => 'object',
                                'description' => 'Product fields: '.implode(', ', McpProductChangeService::PRODUCT_FIELDS)
                                    .'. Variant fields: '.implode(', ', McpProductChangeService::VARIANT_FIELDS)
                                    .'. Prices are exact decimal strings with at most 4 decimals.',
                            ],
                        ]),
                        'idempotency_key' => $key,
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('product_cost.manage')) {
            $builder->addTool(
                [$this->operationTools, 'preparePurchasePriceBulkUpdate'],
                name: 'prepare_purchase_price_bulk_update', title: 'Prepare purchase price bulk update',
                description: 'Prepare variant purchase-cost updates. Each row has variant_id or sku and purchase_price as an exact decimal string (max 4 decimals).'.$prepareDescription,
                annotations: $prepare,
                inputSchema: [
                    'type' => 'object', 'required' => ['rows', 'idempotency_key'],
                    'properties' => [
                        'rows' => $rows([...$locator('variant_id'), 'purchase_price' => ['type' => 'string']]),
                        'idempotency_key' => $key,
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.archive')) {
            $builder->addTool(
                [$this->operationTools, 'prepareProductBulkArchive'],
                name: 'prepare_product_bulk_archive', title: 'Prepare product bulk archive',
                description: 'Prepare deactivation of products and their variants. Each row has product_id or the sku of one of its variants. Nothing is deleted.'.$prepareDescription,
                annotations: $prepare,
                inputSchema: [
                    'type' => 'object', 'required' => ['rows', 'idempotency_key'],
                    'properties' => [
                        'rows' => $rows($locator('product_id')),
                        'idempotency_key' => $key,
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.update') || $this->context->allows('product_cost.manage') || $this->context->allows('products.archive')) {
            $builder->addTool(
                [$this->operationTools, 'executeProductOperation'],
                name: 'execute_product_operation', title: 'Execute product operation',
                description: 'Queue a previewed operation using its exact preview_fingerprint. Only "changed" rows are applied, each independently; the final status is completed, partial, or failed. A changed target or expired preview is rejected: prepare again. Repeating the call with the same fingerprint returns the current status.',
                annotations: $execute,
                inputSchema: [
                    'type' => 'object', 'required' => ['operation_id', 'preview_fingerprint'],
                    'properties' => [
                        'operation_id' => $operationId,
                        'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        $builder->addTool(
            [$this->operationTools, 'getProductOperation'],
            name: 'get_product_operation', title: 'Get product operation',
            description: 'Return status, preview counts, progress, timestamps, and (while previewed) the preview fingerprint of an operation created by this connection.',
            annotations: $readOnly,
            inputSchema: [
                'type' => 'object', 'required' => ['operation_id'],
                'properties' => ['operation_id' => $operationId], 'additionalProperties' => false,
            ], outputSchema: $outputSchema,
        );
        $builder->addTool(
            [$this->operationTools, 'getProductOperationItems'],
            name: 'get_product_operation_items', title: 'Get product operation items',
            description: 'Page through per-row preview and execution results of an operation created by this connection.',
            annotations: $readOnly,
            inputSchema: [
                'type' => 'object', 'required' => ['operation_id'],
                'properties' => [
                    'operation_id' => $operationId,
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('mcp.operations.items_max_per_page', 200), 'default' => 50],
                    'status' => ['type' => 'string', 'enum' => ['ready', 'excluded', 'pending', 'succeeded', 'failed']],
                    'preview_status' => ['type' => 'string', 'enum' => ['changed', 'unchanged', 'skipped', 'invalid', 'conflict']],
                ], 'additionalProperties' => false,
            ], outputSchema: $outputSchema,
        );
    }

    /** @return array<string, mixed> */
    private function productFieldSchema(): array
    {
        return [
            'name' => ['type' => 'string', 'maxLength' => 255],
            'description' => ['type' => ['string', 'null']],
            'image_url' => ['type' => ['string', 'null'], 'maxLength' => 2048],
            'brand_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'category_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'unit_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
        ];
    }

    /** @return array<string, mixed> */
    private function variantFieldSchema(): array
    {
        $decimal = ['type' => ['string', 'null'], 'pattern' => '^\\d+(?:\\.\\d{1,4})?$'];

        return [
            'label' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'sku' => ['type' => 'string', 'maxLength' => 255],
            'reference' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'barcode' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'image_url' => ['type' => ['string', 'null'], 'maxLength' => 2048],
            'purchase_price' => $decimal,
            'public_price_ttc' => $decimal,
            'unit_price_ht' => $decimal,
            'regular_sale_price' => $decimal,
            'promotional_sale_price' => $decimal,
            'default_sale_price' => $decimal,
            'tax_rate_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
        ];
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function updateSchema(string $idField, array $fields): array
    {
        return [
            'type' => 'object', 'required' => [$idField, 'changes', 'preview_fingerprint'],
            'properties' => [
                $idField => ['type' => 'integer', 'minimum' => 1],
                'changes' => ['type' => 'object', 'minProperties' => 1, 'properties' => $fields, 'additionalProperties' => false],
                'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            ],
            'additionalProperties' => false,
        ];
    }
}
