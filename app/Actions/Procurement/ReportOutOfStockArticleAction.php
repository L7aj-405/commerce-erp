<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\OutOfStockArticleStatus;
use App\Enums\SalesOrderLineType;
use App\Models\OutOfStockArticle;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Flags one Custom Sales Order line — a free-text article typed because it is
 * not yet a catalogue Product/ProductVariant — as needing to be sourced or
 * created (§11-§12 of the manual-ops brief). Idempotent: re-flagging the same
 * line returns the existing request rather than raising a duplicate (unique
 * organization_id + sales_order_line_id, mirroring the Part A idempotency
 * strategy).
 */
class ReportOutOfStockArticleAction
{
    use AuthorizesProcurementAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, SalesOrder $order, SalesOrderLine $line, ?string $notes = null): OutOfStockArticle
    {
        $this->authorizeProcurement($actor, $order->organization, 'procurement.manage');

        return DB::transaction(function () use ($actor, $order, $line, $notes) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($order->organization_id === $actor->active_organization_id, 404);

            $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)
                ->where('sales_order_id', $order->getKey())->whereKey($line->getKey())->firstOrFail();

            if ($line->line_type !== SalesOrderLineType::Custom || $line->product_variant_id !== null) {
                throw ValidationException::withMessages(['line' => 'Seule une ligne personnalisée (hors catalogue) peut être signalée.']);
            }

            $existing = OutOfStockArticle::query()
                ->where('organization_id', $order->organization_id)
                ->where('sales_order_line_id', $line->getKey())
                ->first();
            if ($existing) {
                return $existing;
            }

            $article = new OutOfStockArticle;
            $article->organization_id = $order->organization_id;
            $article->store_id = $order->store_id;
            $article->sales_order_id = $order->getKey();
            $article->sales_order_line_id = $line->getKey();
            $article->customer_id = $order->customer_id;
            $article->description = $line->product_name;
            $article->requested_quantity = $line->quantity;
            $article->reference = $line->reference;
            $article->status = OutOfStockArticleStatus::Unresolved;
            $article->requested_by_user_id = $actor->getKey();
            $article->notes = $notes;

            try {
                $article->save();
            } catch (QueryException $exception) {
                // Unique (organization_id, sales_order_line_id) race: a concurrent
                // duplicate/double-click request already created it.
                $existing = OutOfStockArticle::query()
                    ->where('organization_id', $order->organization_id)
                    ->where('sales_order_line_id', $line->getKey())
                    ->first();
                if ($existing) {
                    return $existing;
                }

                throw $exception;
            }

            $this->audit->record('out_of_stock_article.reported', $actor, $order->organization, $order->store, $article, newValues: [
                'sales_order_id' => $order->getKey(), 'sales_order_number' => $order->order_number,
                'sales_order_line_id' => $line->getKey(), 'description' => $article->description,
                'requested_quantity' => $article->requested_quantity,
            ]);

            return $article;
        });
    }
}
