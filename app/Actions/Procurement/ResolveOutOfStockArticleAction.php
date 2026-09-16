<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\CatalogStatus;
use App\Enums\OutOfStockArticleStatus;
use App\Models\OutOfStockArticle;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Links an unresolved "Articles hors stock" request to the real ProductVariant
 * once it has been added to the catalogue (§12-§14). LINK ONLY:
 *  - never fabricates inventory — if physical units are needed, they arrive
 *    through the existing opening-stock / supplier-receipt flow, never here;
 *  - never rewrites the original (already-sold, historical) Custom Sales Order
 *    line — that line stays an accurate record of what was actually agreed at
 *    the time.
 */
class ResolveOutOfStockArticleAction
{
    use AuthorizesProcurementAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, OutOfStockArticle $article, ProductVariant $variant): OutOfStockArticle
    {
        $this->authorizeProcurement($actor, $article->organization, 'procurement.manage');
        abort_unless($variant->organization_id === $article->organization_id, 404);

        if ($variant->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['product_variant_id' => 'Seule une variante active peut être associée.']);
        }

        return DB::transaction(function () use ($actor, $article, $variant) {
            $article = OutOfStockArticle::query()->whereKey($article->getKey())
                ->where('organization_id', $article->organization_id)->lockForUpdate()->firstOrFail();

            if ($article->status === OutOfStockArticleStatus::Resolved) {
                throw ValidationException::withMessages(['article' => 'Cette demande est déjà résolue.']);
            }

            $article->status = OutOfStockArticleStatus::Resolved;
            $article->resolved_product_variant_id = $variant->getKey();
            $article->resolved_by_user_id = $actor->getKey();
            $article->resolved_at = now();
            $article->save();

            $this->audit->record('out_of_stock_article.resolved', $actor, $article->organization, $article->store, $article, oldValues: [
                'status' => OutOfStockArticleStatus::Unresolved->value,
            ], newValues: [
                'status' => OutOfStockArticleStatus::Resolved->value,
                'resolved_product_variant_id' => $variant->getKey(),
                'resolved_product_variant_sku' => $variant->sku,
            ]);

            return $article;
        });
    }
}
