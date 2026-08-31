<?php

namespace Tests\Support;

use App\Actions\Sales\CreateSalesOrderAction;
use App\Models\User;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderDiscountType;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\SalesLineCalculator;
use App\Support\Decimal;
use Illuminate\Support\Str;

abstract class PosTestCase extends SalesTestCase
{
    /** @return array<string, mixed> */
    protected function posPayload(Warehouse $warehouse, array $lines, array $overrides = []): array
    {
        $account = FinancialAccount::query()
            ->where('organization_id', $warehouse->organization_id)
            ->where('code', 'POS-CASH')
            ->first() ?? $this->createPosAccount($warehouse->organization, 'cash', 'POS-CASH', 'POS Cash');
        $total = $this->posExpectedTotal($lines);

        return array_replace([
            'client_operation_id' => (string) Str::uuid(),
            'warehouse_id' => $warehouse->getKey(),
            'customer_id' => null,
            'lines' => $lines,
            'payments' => [$this->posPayment($account, $total, ['cash_received' => $total])],
        ], $overrides);
    }

    protected function createPosAccount(Organization $organization, string $type, string $code, ?string $name = null, string $currency = 'MAD'): FinancialAccount
    {
        $account = new FinancialAccount;
        $account->organization_id = $organization->getKey();
        $account->name = $name ?? $code;
        $account->code = $code;
        $account->type = $type;
        $account->status = 'active';
        $account->currency_code = $currency;
        $account->save();

        return $account;
    }

    protected function createPosDraft(User $actor, Organization $organization, \App\Models\Store $store, Warehouse $warehouse, ?int $customerId = null): SalesOrder
    {
        $this->activate($actor, $organization, $store);
        $order = app(CreateSalesOrderAction::class)->execute($actor, $organization, $store, [
            'customer_id' => $customerId,
            'sale_date' => '2026-08-31',
            'currency_code' => 'MAD',
            'notes' => null,
        ], SalesOrderSource::Pos);
        $order->pos_warehouse_id = $warehouse->getKey();
        $order->pos_global_discount_type = 'none';
        $order->pos_global_discount_value = '0.0000';
        $order->pos_fulfillment_mode = 'pickup';
        $order->pos_shipping_fee = '0.0000';
        $order->save();

        return $order->fresh();
    }

    /** @param list<array<string, mixed>> $payments
     * @return array<string, mixed>
     */
    protected function posDraftCheckoutPayload(SalesOrder $draft, array $payments, array $overrides = []): array
    {
        return array_replace([
            'client_operation_id' => (string) Str::uuid(),
            'order_id' => $draft->getKey(),
            'fulfillment_mode' => $draft->pos_fulfillment_mode ?? 'pickup',
            'payments' => $payments,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function posPayment(FinancialAccount $account, string $amount, array $overrides = []): array
    {
        return array_replace([
            'method' => 'cash',
            'financial_account_id' => $account->getKey(),
            'amount' => $amount,
            'cash_received' => $amount,
            'reference' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function posCatalogLine(ProductVariant $variant, array $overrides = []): array
    {
        return array_replace([
            'line_type' => 'catalog',
            'product_variant_id' => $variant->getKey(),
            'quantity' => '1.0000',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function posCustomLine(array $overrides = []): array
    {
        return array_replace([
            'line_type' => 'custom',
            'description' => 'POS service',
            'reference' => null,
            'unit_label' => 'item',
            'quantity' => '1.0000',
            'unit_price_excl_tax' => '100.0000',
            'tax_rate_id' => null,
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $lines */
    private function posExpectedTotal(array $lines): string
    {
        $total = '0.0000';
        foreach ($lines as $line) {
            if ($line['line_type'] === 'catalog') {
                $variant = ProductVariant::query()->with('taxRate')->findOrFail($line['product_variant_id']);
                $price = $line['unit_price_excl_tax'] ?? $variant->default_sale_price;
                $taxRate = $variant->taxRate?->rate ?? '0.0000';
            } else {
                $price = $line['unit_price_excl_tax'];
                $taxRate = ! empty($line['tax_rate_id']) ? TaxRate::query()->findOrFail($line['tax_rate_id'])->rate : '0.0000';
            }
            $calculated = app(SalesLineCalculator::class)->calculate(
                $line['quantity'],
                $price,
                $taxRate,
                SalesOrderDiscountType::from($line['discount_type'] ?? 'none'),
                $line['discount_value'] ?? '0.0000',
            );
            $total = Decimal::add($total, $calculated['total_incl_tax']);
        }

        return $total;
    }
}
