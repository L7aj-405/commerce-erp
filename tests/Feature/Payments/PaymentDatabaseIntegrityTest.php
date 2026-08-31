<?php

namespace Tests\Feature\Payments;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentTestCase;

class PaymentDatabaseIntegrityTest extends PaymentTestCase
{
    public function test_payment_cannot_reference_store_from_another_organization(): void
    {
        [, $organization, , , $account] = $this->paymentFixture();
        [, , $foreignStore] = $this->paymentFixture();
        $this->assertConstraintRejects(fn () => $this->insertPayment($organization->id, $foreignStore->id, $account->id, 'PAY-BAD-STORE'));
    }

    public function test_payment_cannot_reference_account_from_another_organization(): void
    {
        [, $organization, $store] = $this->paymentFixture();
        [, , , , $foreignAccount] = $this->paymentFixture();
        $this->assertConstraintRejects(fn () => $this->insertPayment($organization->id, $store->id, $foreignAccount->id, 'PAY-BAD-ACCOUNT'));
    }

    public function test_allocation_cannot_cross_payment_tenant(): void
    {
        [$owner, $organization, , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');
        [, $foreignOrganization, , $foreignOrder] = $this->paymentFixture();
        $this->assertConstraintRejects(fn () => DB::table('payment_allocations')->insert([
            'organization_id' => $foreignOrganization->id, 'payment_id' => $payment->id, 'sales_order_id' => $foreignOrder->id,
            'amount' => '1.0000', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_allocation_cannot_cross_sales_order_tenant(): void
    {
        [$owner, $organization, , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');
        [, , , $foreignOrder] = $this->paymentFixture();
        $this->assertConstraintRejects(fn () => DB::table('payment_allocations')->insert([
            'organization_id' => $organization->id, 'payment_id' => $payment->id, 'sales_order_id' => $foreignOrder->id,
            'amount' => '1.0000', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function insertPayment(int $organizationId, int $storeId, int $accountId, string $number): void
    {
        DB::table('payments')->insert([
            'organization_id' => $organizationId, 'store_id' => $storeId, 'financial_account_id' => $accountId,
            'payment_number' => $number, 'method' => 'cash', 'status' => 'posted', 'amount' => '1.0000',
            'currency_code' => 'MAD', 'payment_date' => now()->toDateString(), 'operation_sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertConstraintRejects(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('Expected tenant-aware database constraint rejection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
