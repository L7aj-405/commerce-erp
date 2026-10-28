<?php

namespace App\Services\Mcp\Finance;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Services\Mcp\McpDryRun;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use App\Services\Mcp\Sales\SalesOrderReadService;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Invoice issue for a Sales Order over MCP, through the two canonical ERP
 * steps the web uses: CreateFullInvoiceFromSalesOrderAction (the draft
 * snapshot; reused when a draft already exists) then IssueInvoiceAction
 * (confirmed order, fully paid, no duplicate, seller profile, official
 * number, frozen snapshot, invoice.* audit). The preview runs both in a
 * rolled-back transaction; refusals are labelled from the ERP's own verdict.
 * Issuing never records a payment and never confirms an order.
 */
final class McpInvoiceIssueService
{
    /** Idempotency target type (mcp_idempotency_records.target_type is varchar(32)). */
    public const TARGET_TYPE = 'invoice';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SalesOrderReadService $orders,
        private readonly McpPaymentService $payments,
        private readonly InvoiceReadService $invoices,
        private readonly CreateFullInvoiceFromSalesOrderAction $createDraft,
        private readonly IssueInvoiceAction $issue,
        private readonly McpPartyPreviews $previews,
        private readonly McpDryRun $dryRun,
    ) {}

    /** @return array<string, mixed> */
    public function preview(int $orderId): array
    {
        $result = $this->dryRun->run(function () use ($orderId) {
            $order = $this->orders->find($this->context->organization(), $orderId, lock: true);
            $payment = $this->payments->state($order);
            $existing = $this->latestIssued($order);
            $state = $this->stateHash($order);
            $issues = [];
            $outcome = null;
            try {
                $outcome = $this->outcome($this->run($order));
            } catch (ValidationException $exception) {
                $issues = $this->issues($exception, $order, $payment, $existing);
            } catch (HttpExceptionInterface) {
                $issues[] = ['path' => 'invoice', 'code' => 'not_permitted', 'message' => 'The ERP refused invoicing for this user (it needs invoices.create and invoices.issue, and membership of the order store).'];
            }

            return compact('order', 'payment', 'existing', 'state', 'issues', 'outcome');
        });

        [$fingerprint, $expiresAt] = $result['issues'] === []
            ? $this->previews->issue('invoice_issue', $this->binding($orderId, $result['state']))
            : [null, null];

        return [
            'sales_order' => [
                'id' => $result['order']->getKey(),
                'order_number' => $result['order']->order_number,
                'status' => $result['order']->status->value,
                'customer' => ['id' => $result['order']->customer_id, 'name' => $result['order']->customer_name, 'company' => $result['order']->customer_company],
                'currency_code' => $result['order']->currency_code,
            ],
            'payment' => $result['payment'],
            'existing_invoice' => $result['existing'] ? $this->invoices->summary($result['existing']) : null,
            'invoice_preview' => $result['outcome'],
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => ['invoices.issue'],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'Issuing creates the immutable invoice of this confirmed, fully paid Sales Order with the next official number. It records no payment and changes no order line or price.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @return array<string, mixed>
     */
    public function execute(int $orderId, string $fingerprint): array
    {
        $order = $this->orders->find($this->context->organization(), $orderId, lock: true);
        $binding = $this->binding($orderId, $this->stateHash($order));
        try {
            $this->previews->consume('invoice_issue', $fingerprint, $binding);
        } catch (InvalidArgumentException $exception) {
            // Already invoiced (e.g. by a person meanwhile): say so with the existing invoice.
            if ($existing = $this->latestIssued($order)) {
                throw new McpInvoiceAlreadyIssued((int) $existing->getKey(), $existing->invoice_number);
            }
            throw $exception;
        }

        try {
            $invoice = $this->run($order);
        } catch (Throwable $exception) {
            $this->previews->release('invoice_issue', $fingerprint, $binding);
            throw $exception;
        }

        return [
            'target' => ['type' => self::TARGET_TYPE, 'id' => (int) $invoice->getKey()],
            'invoice' => $this->invoices->present($invoice->fresh(['lines', 'store', 'salesOrder', 'issuedBy:id,name']), withPayment: false),
            'sales_order_payment' => $this->payments->state($order->fresh()),
        ];
    }

    /** The canonical two steps: reuse an existing draft, otherwise create it; then issue. */
    private function run(SalesOrder $order): Invoice
    {
        $draft = Invoice::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->where('status', InvoiceStatus::Draft->value)->latest('id')->first()
            ?? $this->createDraft->execute($this->context->user(), $order);

        return $this->issue->execute($this->context->user(), $draft);
    }

    /**
     * @param array<string, mixed> $payment
     * @return list<array<string, mixed>>
     */
    private function issues(ValidationException $exception, SalesOrder $order, array $payment, ?Invoice $existing): array
    {
        $message = mb_substr((string) (collect($exception->errors())->flatten()->first() ?? 'Rejected.'), 0, 300);
        $field = (string) array_key_first($exception->errors());

        return [match (true) {
            $order->status !== SalesOrderStatus::Confirmed => ['path' => 'sales_order', 'code' => 'sales_order_not_confirmed', 'message' => $message,
                'details' => ['sales_order_id' => $order->getKey(), 'status' => $order->status->value]],
            $existing !== null && $field === 'order' => ['path' => 'sales_order', 'code' => 'invoice_already_issued', 'message' => $message,
                'details' => ['invoice_id' => $existing->getKey(), 'invoice_number' => $existing->invoice_number]],
            Decimal::compare($payment['outstanding_amount'], '0') > 0 => ['path' => 'sales_order', 'code' => 'sales_order_not_fully_paid',
                'message' => 'La commande n’est pas entièrement réglée.',
                'details' => ['sales_order_id' => $order->getKey(), 'total' => $payment['total'], 'paid' => $payment['paid_amount'], 'outstanding' => $payment['outstanding_amount']]],
            str_starts_with($field, 'seller') => ['path' => 'seller_profile', 'code' => 'seller_profile_incomplete', 'message' => $message],
            default => ['path' => 'invoice', 'code' => 'invoice_rejected', 'message' => $message],
        }];
    }

    /** What the issued invoice would contain (number/ids differ per run). @return array<string, mixed> */
    private function outcome(Invoice $invoice): array
    {
        $presented = $this->invoices->present($invoice->fresh(['lines', 'store', 'salesOrder']), withPayment: false);

        return array_intersect_key($presented, array_flip(['status', 'invoice_date', 'currency_code', 'customer', 'lines', 'taxes', 'totals', 'version_number']));
    }

    private function latestIssued(SalesOrder $order): ?Invoice
    {
        return Invoice::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->where('status', InvoiceStatus::Issued->value)->latest('version')->latest('id')->with(['store:id,name', 'salesOrder'])->first();
    }

    /** Order header/lines, payment state and the order's invoices: what eligibility depends on. */
    private function stateHash(SalesOrder $order): string
    {
        $invoices = Invoice::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->orderBy('id')->get(['id', 'status', 'updated_at'])
            ->map(fn (Invoice $invoice) => $invoice->getKey().':'.$invoice->status->value.'@'.$invoice->updated_at?->format('Y-m-d H:i:s.u'))->implode(',');

        return hash('sha256', $this->orders->version($order).'|'.json_encode($this->payments->state($order)).'|'.$invoices);
    }

    /** @return array<string, mixed> */
    private function binding(int $orderId, string $stateHash): array
    {
        return [
            'entity' => 'invoice_issue',
            'mode' => 'issue',
            'target_id' => $orderId,
            'state_hash' => $stateHash,
            'required_scopes' => ['invoices.issue'],
        ];
    }
}
