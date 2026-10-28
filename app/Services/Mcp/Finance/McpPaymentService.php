<?php

namespace App\Services\Mcp\Finance;

use App\Actions\Payments\RecordPaymentAction;
use App\Enums\SalesOrderStatus;
use App\Http\Requests\Payments\RecordSalesOrderPaymentsRequest;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Services\Mcp\McpDryRun;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use App\Services\Mcp\Sales\SalesOrderReadService;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Payments over MCP. The payment is always recorded by RecordPaymentAction
 * (confirmed order only, active account in the order currency accepting the
 * method, backdating permission, no amount above the remaining balance,
 * payment.posted audit, payment status recalculation). The preview runs that
 * same action in a rolled-back transaction, so its verdict is the ERP's.
 * Recording never confirms an order and never issues an invoice.
 */
final class McpPaymentService
{
    /** Idempotency target type (mcp_idempotency_records.target_type is varchar(32)). */
    public const TARGET_TYPE = 'payment';

    public const FIELDS = ['sales_order_id', 'method', 'financial_account_id', 'amount', 'payment_date', 'reference', 'external_reference', 'notes'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SalesOrderReadService $orders,
        private readonly SalesOrderPaymentCalculator $calculator,
        private readonly RecordPaymentAction $record,
        private readonly McpPartyPreviews $previews,
        private readonly McpDryRun $dryRun,
    ) {}

    /**
     * Canonical candidate: known fields, the web payment form rules, today
     * (organization server date, as the ERP action compares) when no date.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        if (array_diff(array_keys($data), self::FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        $payload = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $data);
        $payload['payment_date'] ??= now()->toDateString();
        foreach (['reference', 'external_reference', 'notes'] as $optional) {
            $payload[$optional] ??= null;
        }

        $rules = collect((new RecordSalesOrderPaymentsRequest)->rules())
            ->filter(fn ($rule, string $key) => str_starts_with($key, 'payments.*.'))
            ->mapWithKeys(fn ($rule, string $key) => [substr($key, strlen('payments.*.')) => $rule])->all();
        Validator::make($payload, [...$rules, 'sales_order_id' => ['required', 'integer', 'min:1']])->validate();

        $payload['sales_order_id'] = (int) $payload['sales_order_id'];
        $payload['financial_account_id'] = (int) $payload['financial_account_id'];
        $payload['amount'] = Decimal::normalize((string) $payload['amount']);
        ksort($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $result = $this->dryRun->run(function () use ($payload) {
            $order = $this->orders->find($this->context->organization(), $payload['sales_order_id'], lock: true);
            $before = $this->state($order);
            // Captured BEFORE the action runs: record() must see the same pre-payment state.
            $state = $this->stateHash($order, $payload);
            $issues = [];
            $after = null;
            try {
                $this->record->execute($this->context->user(), $order, $this->actionData($payload), $this->operationId('preview|'.bin2hex(random_bytes(8))));
                $after = $this->state($order->fresh());
            } catch (ValidationException $exception) {
                $issues = $this->issues($exception, $payload, $before, $order);
            } catch (HttpExceptionInterface) {
                $issues[] = ['path' => 'payment', 'code' => 'not_permitted', 'message' => $payload['payment_date'] !== now()->toDateString()
                    ? 'Recording a payment on another date than today needs the ERP payments.backdate permission.'
                    : 'The ERP refused this payment for the connection user (permission or store membership).'];
            } catch (ModelNotFoundException) {
                $issues[] = ['path' => 'payment.financial_account_id', 'code' => 'invalid_financial_account', 'message' => 'The financial account does not exist in this organization. Use search_payment_methods.'];
            }

            return ['order' => $order, 'before' => $before, 'after' => $after, 'issues' => $issues, 'state' => $state];
        });

        [$fingerprint, $expiresAt] = $result['issues'] === []
            ? $this->previews->issue('payment', $this->binding($payload, $result['state']))
            : [null, null];

        return [
            'sales_order' => [
                'id' => $result['order']->getKey(),
                'order_number' => $result['order']->order_number,
                'status' => $result['order']->status->value,
                'customer' => ['id' => $result['order']->customer_id, 'name' => $result['order']->customer_name, 'company' => $result['order']->customer_company],
                'currency_code' => $result['order']->currency_code,
            ],
            'payment' => [
                'method' => $payload['method'],
                'financial_account_id' => $payload['financial_account_id'],
                'amount' => $payload['amount'],
                'payment_date' => $payload['payment_date'],
                'reference' => $payload['reference'],
            ],
            'before' => $result['before'],
            'after' => $result['after'],
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => ['payments.create'],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'Recording posts this payment on the Sales Order only. It does not confirm the order and does not issue an invoice (an invoice can be issued once outstanding_amount is 0).',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper). The
     * operation id is derived from the connection + idempotency key, so the
     * ERP's own payment idempotency also refuses a duplicate.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function record(array $payload, string $fingerprint, string $idempotencyKey): array
    {
        $order = $this->orders->find($this->context->organization(), $payload['sales_order_id'], lock: true);
        $binding = $this->binding($payload, $this->stateHash($order, $payload));
        $this->previews->consume('payment', $fingerprint, $binding);

        try {
            $payment = $this->record->execute($this->context->user(), $order, $this->actionData($payload), $this->operationId('record|'.$idempotencyKey));
        } catch (Throwable $exception) {
            $this->previews->release('payment', $fingerprint, $binding);
            throw $exception;
        }

        return [
            'target' => ['type' => self::TARGET_TYPE, 'id' => (int) $payment->getKey()],
            'payment' => [
                'id' => (int) $payment->getKey(),
                'payment_number' => $payment->payment_number,
                'status' => $payment->status->value,
                'amount' => (string) $payment->amount,
                'currency_code' => $payment->currency_code,
                'payment_date' => $payment->payment_date?->toDateString(),
                'method' => $payment->method->value,
                'financial_account_id' => (int) $payment->financial_account_id,
            ],
            'sales_order_payment' => $this->state($order->fresh()),
        ];
    }

    /**
     * Payment state of an order from SalesOrderPaymentCalculator.
     *
     * @return array<string, mixed>
     */
    public function state(SalesOrder $order): array
    {
        $summary = $this->calculator->summary($order);

        return [
            'total' => $summary['commercial_total'],
            'paid_amount' => $summary['paid'],
            'outstanding_amount' => $summary['remaining'],
            'payment_status' => $summary['status'],
            'fully_paid' => $order->status === SalesOrderStatus::Confirmed && Decimal::compare($summary['remaining'], '0') === 0,
        ];
    }

    /**
     * Label the ERP's refusal. The decision is the action's; the payment
     * state only names it (e.g. an amount above the remaining balance).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $before
     * @return list<array<string, mixed>>
     */
    private function issues(ValidationException $exception, array $payload, array $before, SalesOrder $order): array
    {
        $issues = [];
        foreach ($exception->errors() as $field => $messages) {
            $message = mb_substr((string) ($messages[0] ?? 'Rejected.'), 0, 300);
            $issues[] = match (true) {
                $field === 'order' => ['path' => 'sales_order', 'code' => 'sales_order_not_confirmed', 'message' => $message, 'details' => ['sales_order_id' => $order->getKey(), 'status' => $order->status->value]],
                $field === 'amount' && Decimal::compare($payload['amount'], $before['outstanding_amount']) > 0 => [
                    'path' => 'payment.amount', 'code' => 'amount_exceeds_outstanding', 'message' => $message,
                    'details' => ['amount' => $payload['amount'], 'outstanding_amount' => $before['outstanding_amount']],
                ],
                $field === 'financial_account_id' => ['path' => 'payment.financial_account_id', 'code' => 'invalid_financial_account', 'message' => $message],
                default => ['path' => "payment.{$field}", 'code' => 'invalid', 'message' => $message],
            };
        }

        return $issues;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function actionData(array $payload): array
    {
        return array_intersect_key($payload, array_flip(['method', 'financial_account_id', 'amount', 'payment_date', 'reference', 'external_reference', 'notes']));
    }

    /** Order header/lines + current paid amount + the account: what a recorded payment depends on. */
    private function stateHash(SalesOrder $order, array $payload): string
    {
        $account = FinancialAccount::query()->where('organization_id', $order->organization_id)->whereKey($payload['financial_account_id'])->first(['id', 'updated_at']);

        return hash('sha256', implode('|', [
            $this->orders->version($order),
            $this->calculator->paidAmount($order),
            Payment::query()->where('organization_id', $order->organization_id)->whereHas('allocations', fn ($q) => $q->where('sales_order_id', $order->getKey()))->max('id') ?? '0',
            $account?->updated_at?->format('Y-m-d H:i:s.u') ?? 'missing',
        ]));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function binding(array $payload, string $stateHash): array
    {
        return [
            'entity' => 'payment',
            'mode' => 'record',
            'target_id' => $payload['sales_order_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $stateHash,
            'required_scopes' => ['payments.create'],
        ];
    }

    /** Deterministic RFC-4122-shaped id (payments.client_operation_id is a uuid column). */
    private function operationId(string $seed): string
    {
        $hex = hash('sha256', $this->context->connectionOrFail()->getKey().'|'.$seed);
        $hex[12] = '8';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
