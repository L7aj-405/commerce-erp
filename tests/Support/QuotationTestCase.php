<?php

namespace Tests\Support;

use App\Actions\Quotations\CreateQuotationAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Actions\Quotations\SaveQuotationLineAction;
use App\Actions\Quotations\StartQuotationRevisionAction;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;

abstract class QuotationTestCase extends DocumentTestCase
{
    protected function createQuotation(User $actor, Organization $organization, Store $store, array $data = []): Quotation
    {
        return app(CreateQuotationAction::class)->execute($actor, $organization, $store, array_replace([
            'currency_code' => 'MAD',
            'quotation_date' => now()->toDateString(),
        ], $data));
    }

    protected function addCatalogQuotationLine(User $actor, Quotation $quotation, ProductVariant $variant, array $data = [])
    {
        return app(SaveQuotationLineAction::class)->execute($actor, $quotation, array_replace([
            'line_type' => 'catalog',
            'product_variant_id' => $variant->getKey(),
            'quantity' => '1',
            'discount_type' => 'none',
            'discount_value' => '0',
        ], $data));
    }

    protected function addNonStockQuotationLine(User $actor, Quotation $quotation, array $data = [])
    {
        return app(SaveQuotationLineAction::class)->execute($actor, $quotation, array_replace([
            'line_type' => 'non_stock',
            'name' => 'Projecteur XYZ',
            'price_input_mode' => 'ht',
            'unit_price' => '1000',
            'quantity' => '1',
            'discount_type' => 'none',
            'discount_value' => '0',
        ], $data));
    }

    protected function issueQuotation(User $actor, Quotation $quotation): Quotation
    {
        return app(IssueQuotationAction::class)->execute($actor, $quotation)->fresh(['lines']);
    }

    protected function reviseQuotation(User $actor, Quotation $issued, string $reason = 'Modification demandée par le client.'): Quotation
    {
        return app(StartQuotationRevisionAction::class)->execute($actor, $issued, $reason)->fresh(['lines']);
    }
}
