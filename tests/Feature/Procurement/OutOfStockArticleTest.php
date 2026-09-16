<?php

namespace Tests\Feature\Procurement;

use App\Actions\Procurement\CreateProcurementAction;
use App\Actions\Procurement\ReportOutOfStockArticleAction;
use App\Actions\Procurement\ResolveOutOfStockArticleAction;
use App\Enums\OutOfStockArticleStatus;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\OutOfStockArticle;
use App\Models\Supplier;
use App\Models\User;
use Inertia\Testing\AssertableInertia as AssertableJson;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesTestCase;

/**
 * Part B — "Articles hors stock". A genuinely new article (no ProductVariant
 * yet) cannot go through sales_order_procurements (§15: that table requires an
 * existing product_variant_id), so this exercises the small, explicit
 * OutOfStockArticle model instead.
 */
class OutOfStockArticleTest extends SalesTestCase
{
    private function makeSupplier(Organization $organization, string $name = 'Casa Sin'): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $organization->getKey();
        $supplier->name = $name;
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }

    /** @return array{User, Organization, \App\Models\Store, \App\Models\SalesOrder} */
    private function draftOrderContext(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $order = $this->createDraftOrder($owner, $organization, $store, null);

        return [$owner, $organization, $store, $order];
    }

    public function test_flagging_a_custom_line_creates_an_unresolved_request(): void
    {
        [$owner, $org, , $order] = $this->draftOrderContext();
        $line = $this->addCustomLine($owner, $order, ['description' => 'Microphone Modèle Z', 'quantity' => '3.0000']);

        $article = app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());

        $this->assertSame(OutOfStockArticleStatus::Unresolved, $article->status);
        $this->assertSame('Microphone Modèle Z', $article->description);
        $this->assertSame('3.0000', $article->requested_quantity);
        $this->assertSame($order->getKey(), $article->sales_order_id);
        $this->assertSame($owner->getKey(), $article->requested_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'out_of_stock_article.reported', 'auditable_id' => $article->id]);
    }

    public function test_a_catalog_line_cannot_be_flagged(): void
    {
        [$owner, $org, $store, $order] = $this->draftOrderContext();
        $warehouse = $this->createWarehouse($org);
        $variant = $this->createProduct($org, 'Câble Y', 'CAB-001')->variants->first();
        $this->openStock($owner, $org, $warehouse, $variant, '5.0000');
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse);

        $this->expectException(ValidationException::class);
        app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());
    }

    public function test_reflagging_the_same_line_is_idempotent(): void
    {
        [$owner, , , $order] = $this->draftOrderContext();
        $line = $this->addCustomLine($owner, $order, ['description' => 'Microphone Modèle Z']);

        $first = app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());
        $second = app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OutOfStockArticle::query()->where('sales_order_line_id', $line->getKey())->count());
    }

    public function test_resolving_links_the_chosen_product_variant_and_keeps_the_original_line_untouched(): void
    {
        [$owner, $org, $store, $order] = $this->draftOrderContext();
        $line = $this->addCustomLine($owner, $order, ['description' => 'Microphone Modèle Z']);
        $article = app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());

        $newVariant = $this->createProduct($org, 'Microphone Modèle Z', 'MIC-Z')->variants->first();
        $resolved = app(ResolveOutOfStockArticleAction::class)->execute($owner, $article->fresh(), $newVariant);

        $this->assertSame(OutOfStockArticleStatus::Resolved, $resolved->status);
        $this->assertSame($newVariant->getKey(), $resolved->resolved_product_variant_id);
        $this->assertSame($owner->getKey(), $resolved->resolved_by_user_id);
        $this->assertNotNull($resolved->resolved_at);

        // The historical Custom line itself is never rewritten.
        $line->refresh();
        $this->assertSame('custom', $line->line_type->value);
        $this->assertNull($line->product_variant_id);
        $this->assertSame('Microphone Modèle Z', $line->product_name);
    }

    public function test_resolving_never_creates_inventory_movements_or_balances(): void
    {
        [$owner, $org, , $order] = $this->draftOrderContext();
        $line = $this->addCustomLine($owner, $order, ['description' => 'Microphone Modèle Z']);
        $article = app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());
        $newVariant = $this->createProduct($org, 'Microphone Modèle Z', 'MIC-Z')->variants->first();

        app(ResolveOutOfStockArticleAction::class)->execute($owner, $article->fresh(), $newVariant);

        $this->assertSame(0, InventoryMovement::query()->where('organization_id', $org->getKey())->count());
        $this->assertSame(0, InventoryBalance::query()->where('organization_id', $org->getKey())->where('product_variant_id', $newVariant->getKey())->count());
    }

    public function test_an_unresolved_article_appears_in_the_default_active_queue(): void
    {
        [$owner, , , $order] = $this->draftOrderContext();
        $line = $this->addCustomLine($owner, $order, ['description' => 'Microphone Modèle Z']);
        app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());

        $this->actingAs($owner)
            ->get(route('procurement.out-of-stock-articles.index'))
            ->assertInertia(fn (AssertableJson $page) => $page->has('articles.data', 1)
                ->where('articles.data.0.description', 'Microphone Modèle Z')
                ->where('articles.data.0.status', 'unresolved'));
    }

    public function test_resolving_an_already_resolved_request_is_rejected(): void
    {
        [$owner, $org, , $order] = $this->draftOrderContext();
        $line = $this->addCustomLine($owner, $order, ['description' => 'Microphone Modèle Z']);
        $article = app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());
        $variant = $this->createProduct($org, 'Microphone Modèle Z', 'MIC-Z')->variants->first();
        app(ResolveOutOfStockArticleAction::class)->execute($owner, $article->fresh(), $variant);

        $this->expectException(ValidationException::class);
        app(ResolveOutOfStockArticleAction::class)->execute($owner, $article->fresh(), $variant);
    }

    public function test_resolved_request_disappears_from_the_default_active_queue_but_history_remains(): void
    {
        [$owner, $org, , $order] = $this->draftOrderContext();
        $line = $this->addCustomLine($owner, $order, ['description' => 'Microphone Modèle Z']);
        $article = app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());
        $variant = $this->createProduct($org, 'Microphone Modèle Z', 'MIC-Z')->variants->first();
        app(ResolveOutOfStockArticleAction::class)->execute($owner, $article->fresh(), $variant);

        $this->actingAs($owner)
            ->get(route('procurement.out-of-stock-articles.index'))
            ->assertInertia(fn (AssertableJson $page) => $page->has('articles.data', 0));

        $this->actingAs($owner)
            ->get(route('procurement.out-of-stock-articles.index', ['status' => 'all']))
            ->assertInertia(fn (AssertableJson $page) => $page->has('articles.data', 1));

        $this->assertDatabaseHas('out_of_stock_articles', ['id' => $article->id, 'status' => 'resolved']);
    }

    public function test_an_out_of_stock_article_is_invisible_to_another_organization(): void
    {
        [$owner, $org, , $order] = $this->draftOrderContext();
        $line = $this->addCustomLine($owner, $order, ['description' => 'Microphone Modèle Z']);
        $article = app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $line->fresh());

        $otherOwner = User::factory()->create();
        $this->createOrganization($otherOwner);

        $this->actingAs($otherOwner)
            ->post(route('procurement.out-of-stock-articles.resolve', $article), ['product_variant_id' => 1])
            ->assertNotFound();
    }

    public function test_flagging_one_line_does_not_disturb_an_existing_supplier_procurement_on_another_line(): void
    {
        [$owner, $org, $store, $order] = $this->draftOrderContext();
        $warehouse = $this->createWarehouse($org);
        $catalogVariant = $this->createProduct($org, 'Canapé', 'CAN-001')->variants->first();
        $this->openStock($owner, $org, $warehouse, $catalogVariant, '2.0000');
        $catalogLine = $this->addCatalogLine($owner, $order, $catalogVariant, $warehouse, ['quantity' => '10.0000']);
        $supplier = $this->makeSupplier($org);
        $procurement = app(CreateProcurementAction::class)->execute($owner, $order->fresh(), $catalogLine->fresh(), $supplier, ['quantity' => '8.0000']);

        $customLine = $this->addCustomLine($owner, $order->fresh(), ['description' => 'Microphone Modèle Z']);
        app(ReportOutOfStockArticleAction::class)->execute($owner, $order->fresh(), $customLine->fresh());

        $this->assertSame('pending_supplier', $procurement->fresh()->status->value);
        $this->assertSame(1, OutOfStockArticle::query()->where('organization_id', $org->getKey())->count());
    }
}
