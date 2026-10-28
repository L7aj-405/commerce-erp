<?php

namespace App\Services\Mcp;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\OrganizationContact;
use App\Services\Documents\DocumentEmailRecipientService;
use App\Services\Mcp\Parties\CustomerContactTools;
use App\Services\Mcp\Finance\FinanceToolRegistrar;
use App\Services\Mcp\Parties\McpContactChangeService;
use App\Services\Mcp\Procurement\SupplierSourcingToolRegistrar;
use App\Services\Mcp\Products\McpProductChangeService;
use App\Services\Mcp\Products\ProductOperationTools;
use App\Services\Mcp\Products\ProductReadTools;
use App\Services\Mcp\Products\ProductWriteTools;
use App\Services\Mcp\Sales\SalesAssistantTools;
use App\Services\Mcp\Sales\McpQuotationDocumentService;
use App\Services\Mcp\Sales\McpQuotationLifecycleService;
use App\Services\Mcp\Sales\QuotationDocumentTools;
use App\Services\Mcp\Sales\QuotationLifecycleTools;
use App\Services\Mcp\Sales\SalesOrderTools;
use App\Services\Mcp\Work\WorkCenterToolRegistrar;
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
        private readonly QuotationDocumentTools $quotationDocumentTools,
        private readonly CustomerContactTools $partyTools,
        private readonly WorkCenterToolRegistrar $workCenterTools,
        private readonly SupplierSourcingToolRegistrar $supplierSourcingTools,
        private readonly FinanceToolRegistrar $financeTools,
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
        $this->registerCustomerContactTools($builder, $readOnly, $outputSchema);
        $this->registerSalesOrderTools($builder, $readOnly, $outputSchema);
        $this->registerQuotationLifecycleTools($builder, $readOnly, $outputSchema);
        $this->workCenterTools->register($builder, $readOnly, $outputSchema);
        $this->supplierSourcingTools->register($builder, $readOnly, $outputSchema);
        $this->financeTools->register($builder, $readOnly, $outputSchema);

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
                description: 'Required before issue_quotation. Checks with the ERP issue rules (draft, has lines, validity date, seller profile, supplier sourcing) whether a draft quotation can be issued, without saving anything. Lines not covered by internal stock (and non-stock articles) need confirmed supplier availability: each unresolved line is reported as a supplier_availability_unresolved issue with its quantities. Returns issues and a short-lived single-use preview_fingerprint.',
                annotations: $readOnly, inputSchema: $byId, outputSchema: $outputSchema);
            $builder->addTool([$this->quotationLifecycleTools, 'issueQuotation'],
                name: 'issue_quotation', title: 'Issue quotation',
                description: 'Issue a draft quotation (allocates its official number, freezes it) with the fingerprint from preview_quotation_issue. Rejected while supplier sourcing is unresolved: there is no override over MCP (manual availability confirmation is a web-only admin action) — record the supplier responses first. Does not accept or convert. Retrying with the same fingerprint after success returns the issued quotation.',
                annotations: $transition, inputSchema: $withFingerprint(), outputSchema: $outputSchema);
        }

        if ($this->context->allows(McpQuotationLifecycleService::REVISION_SCOPE)) {
            $builder->addTool([$this->quotationLifecycleTools, 'previewQuotationRevision'],
                name: 'preview_quotation_revision', title: 'Preview quotation revision',
                description: 'Required before create_quotation_revision. An issued/accepted quotation is immutable; to change it, open a revision: a NEW draft copied from it (same customer, lines, prices), linked to it, numbered -R{n} when issued. Runs the ERP revision action without saving anything. reason is required by the ERP.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object', 'required' => ['quotation_id', 'reason'],
                    'properties' => ['quotation_id' => ['type' => 'integer', 'minimum' => 1], 'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000]],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
            $builder->addTool([$this->quotationLifecycleTools, 'createQuotationRevision'],
                name: 'create_quotation_revision', title: 'Create quotation revision',
                description: 'Create the previewed revision draft (the reason is taken from the preview). The source quotation is not modified. Then edit the new draft with preview_quotation/update_quotation_draft and issue it with issue_quotation. Requires an idempotency key.',
                annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
                inputSchema: $withFingerprint(['idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]]),
                outputSchema: $outputSchema);
        }

        if ($this->context->allows('quotations.view')) {
            $builder->addTool([$this->quotationDocumentTools, 'getQuotationDocument'],
                name: 'get_quotation_document', title: 'Get quotation PDF',
                description: 'Generate the ERP quotation PDF (drafts carry the BROUILLON watermark) without changing anything, and return metadata plus a short-lived signed download_url the user can open. include_company_stamp reports whether the stamp apposed on the quotation is rendered; it never applies one.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object', 'required' => ['quotation_id'],
                    'properties' => [
                        'quotation_id' => ['type' => 'integer', 'minimum' => 1],
                        'format' => ['type' => 'string', 'enum' => ['pdf'], 'default' => 'pdf'],
                        'include_company_stamp' => ['type' => 'boolean', 'default' => false],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
        }

        if ($this->context->allows(McpQuotationDocumentService::EMAIL_SCOPE)) {
            $recipientLimit = (int) config('documents.email_recipient_limit', DocumentEmailRecipientService::DEFAULT_LIMIT);
            $recipientList = fn (int $minItems) => [
                'type' => 'array', 'minItems' => $minItems, 'maxItems' => $recipientLimit,
                'items' => [
                    'type' => 'object', 'required' => ['email'],
                    'properties' => ['email' => ['type' => 'string', 'maxLength' => 254], 'name' => ['type' => 'string', 'maxLength' => 255]],
                    'additionalProperties' => false,
                ],
            ];
            $builder->addTool([$this->quotationDocumentTools, 'previewQuotationEmail'],
                name: 'preview_quotation_email', title: 'Preview quotation email',
                description: "Required before send_quotation_email. Validates ONE email of an ISSUED quotation to one or more recipients (to, cc, bcc: one {email, name?} object per address, never several addresses in one string), plus subject, message, PDF attachment and mail configuration, with the ERP rules and template, without sending. Duplicates are sent once (first occurrence kept, to → cc → bcc); at most {$recipientLimit} recipients in total. Without \"to\", the legacy recipient_mode/recipient_email define the To recipient (\"customer\", the default, uses the quotation's customer email); do not combine them with \"to\". Never saves contacts.",
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object', 'required' => ['quotation_id'],
                    'properties' => [
                        'quotation_id' => ['type' => 'integer', 'minimum' => 1],
                        'to' => $recipientList(1),
                        'cc' => $recipientList(0),
                        'bcc' => $recipientList(0),
                        'recipient_mode' => ['type' => 'string', 'enum' => ['customer', 'custom'], 'description' => 'Legacy single recipient; use "to" instead.'],
                        'recipient_email' => ['type' => 'string', 'maxLength' => 254, 'description' => 'Legacy single recipient; use "to" instead.'],
                        'subject' => ['type' => 'string', 'maxLength' => 255],
                        'message' => ['type' => 'string', 'maxLength' => 5000],
                        'include_company_stamp' => ['type' => 'boolean', 'default' => false],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
            $builder->addTool([$this->quotationDocumentTools, 'sendQuotationEmail'],
                name: 'send_quotation_email', title: 'Send quotation email',
                description: 'Send ONE quotation email to external recipients: the exact email previewed by preview_quotation_email (same To/CC/BCC recipients, subject, message and quotation PDF), as a single message. Requires that preview\'s single-use preview_fingerprint; nothing can be changed at send time and no other email can be sent. Idempotent per idempotency_key: a retry with the same key returns the recorded outcome and never sends twice. Sending is synchronous: "sent" means accepted by the mail server, not delivered.',
                annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true),
                inputSchema: $withFingerprint(['idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]]),
                outputSchema: $outputSchema);
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
                description: 'Read-only sales/receivable summary shown on the ERP customer profile: confirmed sales, issued invoices, posted payments, outstanding, last activity. Decimal strings, computed ONLY over documents of the stores the connection user may access — never organization-wide. A null figure means unavailable under the user\'s permissions (never treat null as 0); "0.0000" means visible and actually zero.',
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
    private function registerCustomerContactTools($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $fingerprint = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];
        $idempotencyKey = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];
        $text = fn (int $max, string $description = '') => ['type' => ['string', 'null'], 'maxLength' => $max, ...($description !== '' ? ['description' => $description] : [])];
        $mutation = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);
        $create = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);

        $customerFields = [
            'type' => ['type' => 'string', 'enum' => array_column(CustomerType::cases(), 'value')],
            'display_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'Name shown on documents (for a company, usually the company name).'],
            'company_name' => $text(255),
            'email' => $text(255),
            'phone' => $text(64),
            'whatsapp_username' => $text(36, 'WhatsApp username (3-35 of a-z 0-9 . _, not only digits); a leading @ is removed.'),
            'tax_identifier' => $text(128, 'Tax identifier (ICE).'),
            'billing_address' => $text(5000),
            'notes' => $text(5000, 'Internal notes.'),
            'status' => ['type' => 'string', 'enum' => array_column(CustomerStatus::cases(), 'value'), 'description' => 'Defaults to active on create. "inactive" is how the ERP deactivates a customer (no customer archive exists).'],
        ];
        $customerObject = ['type' => 'object', 'properties' => $customerFields, 'additionalProperties' => false];

        $canCreateCustomer = $this->context->allows('customers.create');
        $canUpdateCustomer = $this->context->allows('customers.update');
        if ($canCreateCustomer || $canUpdateCustomer) {
            $builder->addTool([$this->partyTools, 'previewCustomerChanges'],
                name: 'preview_customer_changes', title: 'Preview customer changes',
                description: 'Required before create_customer/update_customer. Validates a new customer (omit customer_id; type and display_name are required) or changes to an existing one (customer_id + only the fields to change) with the ERP customer form rules, without saving. '
                    .'Returns current/proposed values, changed_fields, issues, informational possible_duplicates (same e-mail or name) and a short-lived single-use preview_fingerprint. Creating a customer never creates a contact.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object', 'required' => ['customer'],
                    'properties' => ['customer_id' => $id, 'customer' => $customerObject],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
        }
        if ($canCreateCustomer) {
            $builder->addTool([$this->partyTools, 'createCustomer'],
                name: 'create_customer', title: 'Create customer',
                description: 'Create one customer exactly as previewed (same customer object and its preview_fingerprint). Returns the new customer id, usable as customer_id in preview_quotation. Requires an idempotency key: a retry with the same key returns the same customer.',
                annotations: $create,
                inputSchema: [
                    'type' => 'object', 'required' => ['customer', 'preview_fingerprint', 'idempotency_key'],
                    'properties' => ['customer' => [...$customerObject, 'required' => ['type', 'display_name']], 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotencyKey],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
        }
        if ($canUpdateCustomer) {
            $builder->addTool([$this->partyTools, 'updateCustomer'],
                name: 'update_customer', title: 'Update customer',
                description: 'Apply previewed changes to a customer (same customer_id and customer object as the preview). Only the given fields change. Rejected when the customer changed since the preview. Existing documents keep their customer snapshot.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['customer_id', 'customer', 'preview_fingerprint'],
                    'properties' => ['customer_id' => $id, 'customer' => [...$customerObject, 'minProperties' => 1], 'preview_fingerprint' => $fingerprint],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
        }

        $contactTypes = OrganizationContact::TYPES;
        if ($this->context->allows('contacts.view')) {
            $builder->addTool([$this->partyTools, 'searchContacts'],
                name: 'search_contacts', title: 'Search contacts',
                description: 'Search the organization address book (contacts are people such as a customer\'s accountant; they are not ERP users and not customers) by name, company, job title, e-mail, phone or WhatsApp. '
                    .'Filter by contact_type, active, or the linked customer_id/supplier_id. Active first, then name. Use a found contact\'s email/full_name as a recipient in preview_quotation_email.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'maxLength' => 255],
                        'contact_type' => ['type' => 'string', 'enum' => $contactTypes],
                        'active' => ['type' => 'boolean'],
                        'customer_id' => $id,
                        'supplier_id' => $id,
                        'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
            $builder->addTool([$this->partyTools, 'getContact'],
                name: 'get_contact', title: 'Get contact',
                description: 'Return one organization contact: identity, contact_type, company and job title, e-mail/phone/WhatsApp, linked customer and supplier, notes, active/archived state, other contacts with the same e-mail, and a version token.',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'required' => ['contact_id'], 'properties' => ['contact_id' => $id], 'additionalProperties' => false],
                outputSchema: $outputSchema);
        }

        $contactFields = [
            'full_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'contact_type' => ['type' => 'string', 'enum' => $contactTypes, 'description' => 'client = a customer\'s person, supplier = a supplier\'s person, internal = own staff, other.'],
            'company_name' => $text(255),
            'job_title' => $text(255, 'Function, e.g. "Comptable".'),
            'email' => $text(254),
            'phone' => $text(64),
            'whatsapp' => $text(64, 'WhatsApp number.'),
            'whatsapp_username' => $text(36, 'WhatsApp username (3-35 of a-z 0-9 . _, not only digits); a leading @ is removed.'),
            'customer_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Explicit link to a customer of this organization (see search_customers); null unlinks. Never inferred.'],
            'supplier_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Explicit link to a supplier of this organization; null unlinks. Never inferred.'],
            'notes' => $text(5000),
            'active' => ['type' => 'boolean', 'description' => 'Defaults to true on create.'],
        ];
        $contactObject = ['type' => 'object', 'properties' => $contactFields, 'additionalProperties' => false];

        $contactModes = array_keys(array_filter(McpContactChangeService::SCOPES, fn (string $scope) => $this->context->allows($scope)));
        if ($contactModes !== []) {
            $builder->addTool([$this->partyTools, 'previewContactChanges'],
                name: 'preview_contact_changes', title: 'Preview contact changes',
                description: 'Required before create_contact/update_contact/archive_contact. Only when the user explicitly asks to save/create/change a contact: an ad-hoc e-mail recipient is NOT a reason to create one. '
                    .'mode create (no contact_id; full_name and contact_type required), update (contact_id + only the fields to change) or archive (contact_id, no contact object); inferred from contact_id when omitted. '
                    .'Validates with the ERP contact rules (linked customer/supplier must belong to this organization) without saving; returns current/proposed values, changed_fields, issues and a short-lived single-use preview_fingerprint.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'properties' => ['mode' => ['type' => 'string', 'enum' => $contactModes], 'contact_id' => $id, 'contact' => $contactObject],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
        }
        if ($this->context->allows('contacts.create')) {
            $builder->addTool([$this->partyTools, 'createContact'],
                name: 'create_contact', title: 'Create contact',
                description: 'Create one organization contact exactly as previewed (same contact object and its preview_fingerprint). Creates no customer, supplier or ERP user. Requires an idempotency key: a retry with the same key returns the same contact.',
                annotations: $create,
                inputSchema: [
                    'type' => 'object', 'required' => ['contact', 'preview_fingerprint', 'idempotency_key'],
                    'properties' => ['contact' => [...$contactObject, 'required' => ['full_name', 'contact_type']], 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotencyKey],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
        }
        if ($this->context->allows('contacts.update')) {
            $builder->addTool([$this->partyTools, 'updateContact'],
                name: 'update_contact', title: 'Update contact',
                description: 'Apply previewed changes to a contact (same contact_id and contact object as the preview). Only the given fields change. Rejected when the contact changed since the preview.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['contact_id', 'contact', 'preview_fingerprint'],
                    'properties' => ['contact_id' => $id, 'contact' => [...$contactObject, 'minProperties' => 1], 'preview_fingerprint' => $fingerprint],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
        }
        if ($this->context->allows('contacts.archive')) {
            $builder->addTool([$this->partyTools, 'archiveContact'],
                name: 'archive_contact', title: 'Archive contact',
                description: 'Archive a contact with the fingerprint from preview_contact_changes (mode archive): it becomes inactive and is no longer offered as an e-mail recipient. Nothing is deleted. Retrying with the same fingerprint after success returns the archived contact.',
                annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
                inputSchema: [
                    'type' => 'object', 'required' => ['contact_id', 'preview_fingerprint'],
                    'properties' => ['contact_id' => $id, 'preview_fingerprint' => $fingerprint],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema);
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
