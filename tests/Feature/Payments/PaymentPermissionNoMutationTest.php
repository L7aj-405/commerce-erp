<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaymentTestCase;

/**
 * Phase 1 final UAT (Step 6): complements PaymentPermissionMatrixTest.
 *
 * - Adds the paid-order cancellation route (SalesOrderPolicy::cancel requires payments.refund when posted payments exist).
 * - Proves a denied (403) call leaves the whole financial state byte-identical: payments, refunds, allocations,
 *   financial accounts, order status and audit log are snapshotted before and after.
 */
class PaymentPermissionNoMutationTest extends PaymentTestCase
{
    /** @return array<string, array{bool, bool}> [holds payments.refund, holds payments.reverse] */
    public static function roles(): array
    {
        return [
            'refund only' => [true, false],
            'reverse only' => [false, true],
            'both' => [true, true],
            'neither' => [false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_payment_reversal_route_matrix_and_no_mutation_when_denied(bool $refund, bool $reverse): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture('500.0000');
        $payment = $this->recordPayment($owner, $order, $account, '500.0000');
        $member = $this->member($organization, $store, ['payments.view'], $refund, $reverse);
        $before = $this->financialSnapshot();

        $response = $this->actingAs($member)->post(route('payments.reverse', $payment), ['reason' => 'UAT matrice']);

        if ($reverse) {
            $this->assertNotSame(403, $response->status());
            $this->assertSame('reversed', $payment->fresh()->status->value);
        } else {
            $response->assertForbidden();
            $this->assertSame($before, $this->financialSnapshot(), 'a denied reversal must not mutate any financial table');
        }
        $this->assertSame(0, PaymentRefund::query()->count(), 'reversal never creates a refund');
    }

    #[DataProvider('roles')]
    public function test_paid_order_cancellation_requires_refund_and_never_reverse(bool $refund, bool $reverse): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture('500.0000');
        $payment = $this->recordPayment($owner, $order, $account, '500.0000');
        $member = $this->member($organization, $store, ['sales_orders.view', 'sales_orders.cancel', 'payments.view'], $refund, $reverse);
        $before = $this->financialSnapshot();

        $response = $this->actingAs($member)->post(route('sales.orders.cancel', $order), ['reason' => 'UAT matrice']);

        if ($refund) {
            $response->assertSessionHasNoErrors();
            $this->assertSame('cancelled', $order->fresh()->status->value);
            $refundRow = PaymentRefund::query()->sole();
            $this->assertSame($payment->id, $refundRow->payment_id);
            $this->assertSame('500.0000', $refundRow->amount);
            $this->assertTrue(AuditLog::query()->where('event', 'payment.refunded')->exists(), 'the refund is audited');
        } else {
            $response->assertForbidden();
            $this->assertSame($before, $this->financialSnapshot(), 'a denied cancellation must not mutate any financial table');
            $this->assertNotSame('cancelled', $order->fresh()->status->value);
            $this->assertFalse(AuditLog::query()->where('event', 'payment.refunded')->exists());
        }
    }

    public function test_an_order_without_posted_payments_can_be_cancelled_without_the_refund_permission(): void
    {
        [, $organization, $store, $order] = $this->paymentFixture('500.0000');
        $member = $this->member($organization, $store, ['sales_orders.view', 'sales_orders.cancel'], false, false);

        $this->actingAs($member)->post(route('sales.orders.cancel', $order), ['reason' => 'UAT sans paiement'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame(0, PaymentRefund::query()->count());
    }

    /** @param list<string> $base */
    private function member($organization, $store, array $base, bool $refund, bool $reverse): User
    {
        $user = User::factory()->create();
        $permissions = array_merge($base, $refund ? ['payments.refund'] : [], $reverse ? ['payments.reverse'] : []);
        $this->addOrganizationMember($organization, $user, $permissions, roleName: 'UAT '.Str::random(6));
        $this->addStoreMember($store, $user);
        $this->activate($user, $organization, $store);

        return $user;
    }

    /** Deterministic fingerprint of every financial table a payment permission could touch. */
    private function financialSnapshot(): string
    {
        $tables = ['payments', 'payment_refunds', 'payment_allocations', 'financial_accounts', 'sales_orders', 'audit_logs'];
        $state = [];
        foreach ($tables as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
