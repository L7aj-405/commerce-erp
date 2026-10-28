<?php

namespace App\Services\Mcp\Sales;

use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\DecideQuotationAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Actions\Quotations\StartQuotationRevisionAction;
use App\Enums\QuotationLineType;
use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\NonStockItem;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\Mcp\McpDryRun;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\QuotationSourcing;
use App\Support\Decimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Quotation lifecycle over MCP: issue → accept → convert to a Sales Order.
 *
 * Every transition is the authoritative ERP action (IssueQuotationAction,
 * DecideQuotationAction::accept, ConvertQuotationToSalesOrderAction). Previews
 * run that action inside a rolled-back transaction, so eligibility is exactly
 * the ERP's; the quotation is always the source of truth for conversion.
 */
final class McpQuotationLifecycleService
{
    /** transition => [required MCP scope, resulting quotation status] */
    public const TRANSITIONS = [
        'issue' => ['quotations.issue', 'issued'],
        'accept' => ['quotations.accept', 'accepted'],
        'convert' => ['quotations.convert', 'converted'],
    ];

    /** StartQuotationRevisionAction is authorized by the ERP permission quotations.issue. */
    public const REVISION_SCOPE = 'quotations.issue';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly QuotationReadService $quotations,
        private readonly SalesOrderReadService $salesOrders,
        private readonly IssueQuotationAction $issueAction,
        private readonly DecideQuotationAction $decideAction,
        private readonly ConvertQuotationToSalesOrderAction $convertAction,
        private readonly StartQuotationRevisionAction $reviseAction,
        private readonly McpDryRun $dryRun,
        private readonly McpIdempotencyService $canonical,
        private readonly QuotationSourcing $sourcing,
    ) {}

    public static function scope(string $transition): string
    {
        return self::TRANSITIONS[$transition][0] ?? throw new InvalidArgumentException('invalid_transition');
    }

    /** @return array<string, mixed> */
    public function preview(string $transition, int $quotationId): array
    {
        $scope = self::scope($transition);
        $result = $this->dryRun->run(function () use ($transition, $quotationId) {
            $quotation = $this->quotations->find($this->context->organization(), $quotationId, lock: true);
            $state = $this->state($transition, $quotation);
            $summary = $this->summary($quotation);
            $issues = [];
            $outcome = null;
            $existingOrder = null;

            if ($transition === 'convert' && $quotation->converted_sales_order_id) {
                $existingOrder = $this->existingOrder($quotation);
                $issues[] = ['path' => 'quotation', 'code' => 'already_converted', 'message' => 'This quotation was already converted; its Sales Order is returned instead of creating another one.'];
            } else {
                try {
                    $outcome = $this->apply($transition, $quotation);
                } catch (ValidationException $exception) {
                    // The ERP issue gate refused on supplier sourcing: explain each unresolved line.
                    $sourcingIssues = $transition === 'issue' && isset($exception->errors()['sourcing']) ? $this->sourcingIssues($quotation) : [];
                    array_push($issues, ...($sourcingIssues ?: [['path' => 'quotation', 'code' => 'transition_rejected', 'message' => mb_substr((string) (collect($exception->errors())->flatten()->first() ?? 'Rejected.'), 0, 300)]]));
                } catch (HttpExceptionInterface) {
                    $issues[] = ['path' => 'quotation', 'code' => 'not_permitted', 'message' => $transition === 'convert'
                        ? 'The ERP refused the conversion for this user (it also needs sales_orders.create/update, plus price-override/discount permissions when the quotation uses them).'
                        : 'The ERP refused this transition for this user.'];
                } catch (McpQuotationAlreadyConverted $exception) {
                    $issues[] = ['path' => 'quotation', 'code' => 'already_converted', 'message' => 'This quotation was already converted.'];
                }
            }

            return compact('quotation', 'state', 'summary', 'issues', 'outcome', 'existingOrder');
        });

        $fingerprint = null;
        $expiresAt = null;
        if ($result['issues'] === []) {
            $fingerprint = bin2hex(random_bytes(32));
            $expires = now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300));
            Cache::put($this->previewKey($fingerprint), [
                'organization_id' => (int) $this->context->organization()->getKey(),
                'connection_id' => (string) $this->context->connectionOrFail()->getKey(),
                'transition' => $transition,
                'quotation_id' => $quotationId,
                'required_scopes' => [$scope],
                'state_hash' => $result['state'],
                'outcome_hash' => $result['outcome']['hash'] ?? null,
            ], $expires);
            $expiresAt = $expires->toIso8601String();
        }

        return [
            'transition' => $transition,
            'quotation_id' => $quotationId,
            'quotation_number' => $result['summary']['quotation_number'],
            'current_status' => $result['summary']['status'],
            'proposed_status' => self::TRANSITIONS[$transition][1],
            'quotation' => $result['summary'],
            ...($transition === 'convert' ? [
                'sales_order_preview' => $result['outcome']['sales_order'] ?? null,
                'stock_warnings' => $result['outcome']['stock_warnings'] ?? [],
                'existing_sales_order' => $result['existingOrder'],
            ] : []),
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => [$scope],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => match ($transition) {
                'issue' => 'Issuing allocates the official quotation number and freezes the document. It does not accept or convert.',
                'accept' => 'Accepting records the customer decision. It does not convert or create a Sales Order.',
                default => 'Conversion creates one DRAFT Sales Order from the quotation itself (not confirmed, no stock reserved, no invoice, no payment).',
            },
        ];
    }

    /**
     * Execute a previewed transition through the ERP action. Must run inside
     * the caller's transaction so a stale outcome rolls everything back.
     *
     * @return array{quotation: Quotation, sales_order: ?SalesOrder, stock_warnings: list<array<string, string>>, replay: bool}
     */
    public function execute(string $transition, int $quotationId, string $fingerprint): array
    {
        $scope = self::scope($transition);
        $quotation = $this->quotations->find($this->context->organization(), $quotationId, lock: true);

        // Safe retry after success with the same fingerprint (issue / accept).
        // (Conversion retries are covered by the idempotency key and the ERP's
        // converted_sales_order_id guard instead.)
        $done = $transition !== 'convert' ? Cache::get($this->doneKey($fingerprint)) : null;
        if (is_array($done) && (int) ($done['quotation_id'] ?? 0) === $quotationId && ($done['transition'] ?? null) === $transition
            && hash_equals((string) ($done['connection_id'] ?? ''), (string) $this->context->connectionOrFail()->getKey())
            && $quotation->status->value === self::TRANSITIONS[$transition][1]) {
            return ['quotation' => $quotation, 'sales_order' => null, 'stock_warnings' => [], 'replay' => true];
        }

        $stored = preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? Cache::get($this->previewKey($fingerprint)) : null;
        if ($transition === 'convert' && $quotation->converted_sales_order_id) {
            $existing = $this->existingOrder($quotation);
            throw new McpQuotationAlreadyConverted((int) $quotation->converted_sales_order_id, $existing['order_number'] ?? null);
        }
        if (! is_array($stored)
            || (int) ($stored['organization_id'] ?? 0) !== (int) $this->context->organization()->getKey()
            || ! hash_equals((string) ($stored['connection_id'] ?? ''), (string) $this->context->connectionOrFail()->getKey())
            || ($stored['transition'] ?? null) !== $transition
            || (int) ($stored['quotation_id'] ?? 0) !== $quotationId
            || ($stored['required_scopes'] ?? null) !== [$scope]
            || ! hash_equals((string) ($stored['state_hash'] ?? ''), $this->state($transition, $quotation))) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }

        $outcome = $this->apply($transition, $quotation);
        if ($transition === 'convert' && ! hash_equals((string) ($stored['outcome_hash'] ?? ''), (string) $outcome['hash'])) {
            // Thrown inside the caller's transaction: the conversion rolls back.
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        Cache::forget($this->previewKey($fingerprint));
        Cache::put($this->doneKey($fingerprint), [
            'quotation_id' => $quotationId, 'transition' => $transition,
            'connection_id' => (string) $this->context->connectionOrFail()->getKey(),
        ], now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300) * 2));

        return [
            'quotation' => $this->quotations->find($this->context->organization(), $quotationId),
            'sales_order' => $outcome['order'] ?? null,
            'stock_warnings' => $outcome['stock_warnings'] ?? [],
            'replay' => false,
        ];
    }

    /* ------------------------------------------------------------------
     |  Revisions (StartQuotationRevisionAction; ERP permission quotations.issue)
     * ---------------------------------------------------------------- */

    /** @return array<string, mixed> */
    public function previewRevision(int $quotationId, string $reason): array
    {
        $reason = trim($reason);
        $result = $this->dryRun->run(function () use ($quotationId, $reason) {
            $source = $this->quotations->find($this->context->organization(), $quotationId, lock: true);
            $state = $this->state('revise', $source);
            $summary = $this->summary($source);
            $issues = [];
            $revision = null;
            try {
                $revision = $this->revisionOutcome($this->reviseAction->execute($this->context->user(), $source, $reason));
            } catch (ValidationException $exception) {
                $issues[] = ['path' => 'quotation', 'code' => 'revision_rejected', 'message' => mb_substr((string) (collect($exception->errors())->flatten()->first() ?? 'Rejected.'), 0, 300)];
            } catch (HttpExceptionInterface) {
                $issues[] = ['path' => 'quotation', 'code' => 'not_permitted', 'message' => 'The ERP refused the revision for this user.'];
            }

            return compact('state', 'summary', 'issues', 'revision');
        });

        $fingerprint = null;
        $expiresAt = null;
        if ($result['issues'] === []) {
            $fingerprint = bin2hex(random_bytes(32));
            $expires = now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300));
            Cache::put($this->previewKey($fingerprint), [
                'organization_id' => (int) $this->context->organization()->getKey(),
                'connection_id' => (string) $this->context->connectionOrFail()->getKey(),
                'transition' => 'revise',
                'quotation_id' => $quotationId,
                'required_scopes' => [self::REVISION_SCOPE],
                'state_hash' => $result['state'],
                'outcome_hash' => $result['revision']['hash'],
                // The reason is fixed by the preview; execution never takes a new one.
                'reason' => $reason,
            ], $expires);
            $expiresAt = $expires->toIso8601String();
        }

        return [
            'source_quotation' => $result['summary'],
            'revision_preview' => $result['revision']['preview'] ?? null,
            'reason' => $reason,
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => [self::REVISION_SCOPE],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'A revision is a NEW draft quotation copied from the issued one; the source stays immutable until the revision is issued (then it becomes superseded). Nothing is issued, sent, accepted or converted.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @return array{source: Quotation, revision: Quotation}
     */
    public function createRevision(int $quotationId, string $fingerprint): array
    {
        $source = $this->quotations->find($this->context->organization(), $quotationId, lock: true);
        $stored = preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? Cache::get($this->previewKey($fingerprint)) : null;
        if (! is_array($stored)
            || (int) ($stored['organization_id'] ?? 0) !== (int) $this->context->organization()->getKey()
            || ! hash_equals((string) ($stored['connection_id'] ?? ''), (string) $this->context->connectionOrFail()->getKey())
            || ($stored['transition'] ?? null) !== 'revise'
            || (int) ($stored['quotation_id'] ?? 0) !== $quotationId
            || ($stored['required_scopes'] ?? null) !== [self::REVISION_SCOPE]
            || ! hash_equals((string) ($stored['state_hash'] ?? ''), $this->state('revise', $source))) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }

        $revision = $this->reviseAction->execute($this->context->user(), $source, (string) $stored['reason']);
        if (! hash_equals((string) $stored['outcome_hash'], $this->revisionOutcome($revision)['hash'])) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        Cache::forget($this->previewKey($fingerprint));

        return [
            'source' => $this->quotations->find($this->context->organization(), $quotationId),
            'revision' => $this->quotations->find($this->context->organization(), (int) $revision->getKey()),
        ];
    }

    /**
     * One structured issue per line the issue gate refused, from the same
     * QuotationSourcing evaluation the gate uses (never recomputed here).
     *
     * @return list<array<string, mixed>>
     */
    private function sourcingIssues(Quotation $quotation): array
    {
        $issues = [];
        foreach ($this->sourcing->unresolved($this->sourcing->evaluate($quotation)) as $line) {
            $remaining = Decimal::subtract($line['shortage'], $line['covered_quantity']);
            $issues[] = [
                'path' => "quotation.lines.{$line['line_id']}.sourcing",
                'code' => 'supplier_availability_unresolved',
                'message' => 'La disponibilité fournisseur n’est pas encore confirmée.',
                'details' => [
                    'quotation_line_id' => $line['line_id'],
                    'article' => $line['article'],
                    'article_type' => $line['line_type'],
                    'requested_quantity' => $line['requested_quantity'],
                    'internal_available_quantity' => $line['internal_available'],
                    'shortage_quantity' => $line['shortage'],
                    'confirmed_quantity' => $line['covered_quantity'],
                    'remaining_quantity' => Decimal::compare($remaining, '0') < 0 ? '0.0000' : $remaining,
                    'state' => $line['state'],
                    'pending_request_ids' => array_values(array_column(array_filter($line['requests'], fn (array $r) => $r['status'] === 'pending'), 'id')),
                ],
            ];
        }

        return $issues;
    }

    /** @return array{preview: array<string, mixed>, hash: string} */
    private function revisionOutcome(Quotation $revision): array
    {
        $presented = $this->quotations->present($revision->fresh(['lines', 'store']));
        $preview = [
            'status' => $presented['status'],
            'editable' => $presented['editable'],
            'revision_number' => $presented['revision_number'],
            'store' => $presented['store'],
            'customer' => $presented['customer'],
            'quotation_date' => $presented['quotation_date'],
            'valid_until' => $presented['valid_until'],
            'currency_code' => $presented['currency_code'],
            'lines' => array_map(fn (array $line) => array_diff_key($line, ['line_id' => true]), $presented['lines']),
            'totals' => $presented['totals'],
        ];

        // The new quotation's id is not part of the outcome: it differs per run.
        return ['preview' => $preview, 'hash' => hash('sha256', $this->canonical->canonicalJson($preview))];
    }

    /** @return array<string, mixed> */
    private function apply(string $transition, Quotation $quotation): array
    {
        $user = $this->context->user();

        return match ($transition) {
            'issue' => ['quotation' => $this->issueAction->execute($user, $quotation)],
            'accept' => ['quotation' => $this->decideAction->accept($user, $quotation)],
            default => $this->convert($quotation),
        };
    }

    /** @return array<string, mixed> */
    private function convert(Quotation $quotation): array
    {
        // Same call as the web conversion with no explicit warehouse.
        $result = $this->convertAction->execute($this->context->user(), $quotation, ['warehouse_id' => null]);
        if ($result['reused']) {
            throw new McpQuotationAlreadyConverted((int) $result['order']->getKey(), $result['order']->order_number);
        }
        $order = $result['order']->fresh(['lines', 'store']);
        $lines = $order->lines->map(fn (SalesOrderLine $line) => array_diff_key($this->salesOrders->line($line), ['line_id' => true]))->values()->all();
        $preview = [
            'status' => $order->status->value,
            'store' => ['id' => $order->store_id, 'name' => $order->store?->name],
            'customer' => ['id' => $order->customer_id, 'name' => $order->customer_name, 'company' => $order->customer_company],
            'currency_code' => $order->currency_code,
            'sale_date' => $order->sale_date?->toDateString(),
            'lines' => $lines,
            'totals' => $this->salesOrders->totals($order),
        ];

        return [
            'order' => $order,
            'stock_warnings' => $result['stock_warnings'],
            'sales_order' => $preview,
            // Order id/number are not part of the outcome: they differ per run.
            'hash' => hash('sha256', $this->canonical->canonicalJson($preview)),
        ];
    }

    /** Quotation + line versions, plus (conversion) customer, store and article states. */
    private function state(string $transition, Quotation $quotation): string
    {
        $state = ['q:'.$this->quotations->version($quotation)];
        if ($transition === 'convert') {
            $state[] = 's:'.$quotation->store_id;
            if ($quotation->customer_id) {
                $customer = Customer::query()->where('organization_id', $quotation->organization_id)->whereKey($quotation->customer_id)->first(['id', 'updated_at']);
                $state[] = 'c:'.$quotation->customer_id.'@'.$customer?->updated_at?->format('Y-m-d H:i:s.u');
            }
            $variantIds = $quotation->lines->where('line_type', QuotationLineType::Catalog)->pluck('product_variant_id')->filter()->unique()->values()->all();
            foreach (ProductVariant::query()->with('product:id,updated_at')->where('organization_id', $quotation->organization_id)->whereIn('id', $variantIds)->get(['id', 'product_id', 'updated_at']) as $variant) {
                $state[] = 'v:'.$variant->getKey().'@'.$variant->updated_at?->format('Y-m-d H:i:s.u').'/p@'.$variant->product?->updated_at?->format('Y-m-d H:i:s.u');
            }
            $itemIds = $quotation->lines->pluck('non_stock_item_id')->filter()->unique()->values()->all();
            foreach (NonStockItem::query()->where('organization_id', $quotation->organization_id)->whereIn('id', $itemIds)->get(['id', 'updated_at']) as $item) {
                $state[] = 'n:'.$item->getKey().'@'.$item->updated_at?->format('Y-m-d H:i:s.u');
            }
        }
        sort($state);

        return hash('sha256', implode('|', $state));
    }

    /** @return array<string, mixed> */
    private function summary(Quotation $quotation): array
    {
        $presented = $this->quotations->present($quotation);

        return [
            'id' => $presented['id'],
            'quotation_number' => $presented['quotation_number'],
            'status' => $presented['status'],
            'store' => $presented['store'],
            'customer' => ['id' => $presented['customer']['id'], 'name' => $presented['customer']['name'], 'company' => $presented['customer']['company']],
            'quotation_date' => $presented['quotation_date'],
            'valid_until' => $presented['valid_until'],
            'is_past_validity' => $presented['is_past_validity'],
            'currency_code' => $presented['currency_code'],
            'line_count' => count($presented['lines']),
            'totals' => $presented['totals'],
            'converted_sales_order_id' => $quotation->converted_sales_order_id,
            'version' => $presented['version'],
        ];
    }

    /** @return array{id: int, order_number: ?string, status: ?string}|null */
    private function existingOrder(Quotation $quotation): ?array
    {
        $order = SalesOrder::query()->where('organization_id', $quotation->organization_id)->whereKey($quotation->converted_sales_order_id)->first(['id', 'order_number', 'status']);

        return $order ? ['id' => (int) $order->getKey(), 'order_number' => $order->order_number, 'status' => $order->status->value] : null;
    }

    private function previewKey(string $fingerprint): string
    {
        return 'mcp:quotation-transition-preview:'.hash('sha256', $fingerprint);
    }

    private function doneKey(string $fingerprint): string
    {
        return 'mcp:quotation-transition-done:'.hash('sha256', $fingerprint);
    }
}
