<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\Finance\ReverseFinanceOutgoingPaymentAction;
use App\Models\FinanceObligation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\FinanceObligationCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

class FinanceMonthlyObligationTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withFreshAuthentication(level: 1);
    }

    public function test_obligation_can_be_created_edited_and_cancelled_without_stored_financial_projections(): void
    {
        [$owner, $organization, $store, $account, $supplier] = $this->fixture();
        $obligation = $this->obligation($owner, $organization, ['store_id' => $store->id, 'supplier_id' => $supplier->id, 'preferred_financial_account_id' => $account->id]);

        $this->assertSame('OBL-2026-000001', $obligation->obligation_number);
        $this->assertArrayNotHasKey('paid_amount', $obligation->getAttributes());
        $updated = app(ManageFinanceObligationAction::class)->update($owner, $obligation, $this->obligationData(['label' => 'Loyer showroom', 'planned_amount' => '12000.0000']));
        $this->assertSame('Loyer showroom', $updated->label);
        $this->assertSame('12000.0000', $updated->planned_amount);

        app(ManageFinanceObligationAction::class)->cancel($owner, $updated, 'Bail résilié');
        $this->assertNotNull($updated->fresh()->cancelled_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance_obligation.cancelled', 'auditable_id' => (string) $obligation->id]);
    }

    public function test_partial_full_and_reversed_payments_are_derived_exactly(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        $obligation = $this->obligation($owner, $organization);
        $calculator = app(FinanceObligationCalculator::class);

        $first = $this->payment($owner, $organization, $account->id, [[$obligation->id, '7000.0000']], '7000.0000');
        $partial = $calculator->values($obligation->fresh(), Carbon::parse('2026-10-01'));
        $this->assertSame('7000.0000', $partial['paid']);
        $this->assertSame('3000.0000', $partial['remaining']);
        $this->assertSame('partial', $partial['status']);

        $second = $this->payment($owner, $organization, $account->id, [[$obligation->id, '3000.0000']], '3000.0000');
        $this->assertSame('paid', $calculator->values($obligation->fresh(), Carbon::parse('2026-10-01'))['status']);

        app(ReverseFinanceOutgoingPaymentAction::class)->execute($owner, $second, 'Paiement bancaire rejeté');
        $afterReverse = $calculator->values($obligation->fresh(), Carbon::parse('2026-10-01'));
        $this->assertSame('7000.0000', $afterReverse['paid']);
        $this->assertSame('3000.0000', $afterReverse['remaining']);
        $this->assertSame('reversed', $second->fresh()->status->value);
        $this->assertDatabaseHas('finance_outgoing_payment_allocations', ['outgoing_payment_id' => $second->id, 'obligation_id' => $obligation->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance_outgoing_payment.reversed', 'auditable_id' => (string) $second->id]);
    }

    public function test_overallocation_is_rejected_atomically_and_paid_obligation_cannot_be_cancelled_or_reduced_below_paid(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        $obligation = $this->obligation($owner, $organization);
        $this->payment($owner, $organization, $account->id, [[$obligation->id, '7000.0000']], '7000.0000');

        try {
            $this->payment($owner, $organization, $account->id, [[$obligation->id, '3000.0001']], '3000.0001');
            $this->fail('Expected locked remaining-balance validation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('finance_outgoing_payments', 1);
            $this->assertDatabaseCount('finance_outgoing_payment_allocations', 1);
        }

        foreach ([
            fn () => app(ManageFinanceObligationAction::class)->cancel($owner, $obligation, 'Tentative invalide'),
            fn () => app(ManageFinanceObligationAction::class)->update($owner, $obligation, $this->obligationData(['planned_amount' => '6999.9999'])),
            fn () => app(ManageFinanceObligationAction::class)->update($owner, $obligation, $this->obligationData(['category' => 'tax'])),
        ] as $operation) {
            try { $operation(); $this->fail('Expected paid-history protection.'); } catch (ValidationException) {}
        }
    }

    public function test_one_payment_can_allocate_across_multiple_obligations_without_exceeding_its_total(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        $first = $this->obligation($owner, $organization, ['label' => 'CNSS', 'planned_amount' => '3000.0000']);
        $second = $this->obligation($owner, $organization, ['label' => 'Loyer', 'planned_amount' => '7000.0000']);
        $payment = $this->payment($owner, $organization, $account->id, [[$first->id, '3000.0000'], [$second->id, '7000.0000']], '10000.0000');

        $this->assertCount(2, $payment->allocations);
        $this->assertSame('paid', app(FinanceObligationCalculator::class)->values($first, Carbon::parse('2026-10-01'))['status']);
        $this->assertSame('paid', app(FinanceObligationCalculator::class)->values($second, Carbon::parse('2026-10-01'))['status']);
    }

    public function test_outgoing_payment_idempotency_never_creates_a_duplicate_or_accepts_changed_payload(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        $obligation = $this->obligation($owner, $organization);
        $key = (string) Str::uuid();
        $data = [
            'store_id' => null, 'financial_account_id' => $account->id, 'supplier_id' => null, 'beneficiary_name' => 'Bénéficiaire',
            'payment_method' => 'bank_transfer', 'amount' => '1000.0000', 'currency_code' => 'MAD', 'payment_date' => '2026-10-10',
            'reference' => null, 'notes' => null, 'idempotency_key' => $key,
            'allocations' => [['obligation_id' => $obligation->id, 'amount' => '1000.0000']],
        ];
        $action = app(RecordFinanceOutgoingPaymentAction::class);
        $first = $action->execute($owner, $organization, $data);
        $this->assertSame($first->id, $action->execute($owner, $organization, $data)->id);
        $this->assertDatabaseCount('finance_outgoing_payments', 1);

        try {
            $action->execute($owner, $organization, array_replace($data, [
                'amount' => '1000.0001',
                'allocations' => [['obligation_id' => $obligation->id, 'amount' => '1000.0001']],
            ]));
            $this->fail('Expected changed idempotency payload rejection.');
        } catch (ValidationException) {}
        $this->assertDatabaseCount('finance_outgoing_payments', 1);
    }

    public function test_foreign_tenant_references_are_rejected_before_any_mutation(): void
    {
        [$ownerA, $organizationA] = $this->fixture();
        [$ownerB, $organizationB, $storeB, $accountB, $supplierB] = $this->fixture();

        foreach ([['store_id' => $storeB->id], ['supplier_id' => $supplierB->id], ['preferred_financial_account_id' => $accountB->id]] as $foreign) {
            try {
                $this->obligation($ownerA, $organizationA, $foreign);
                $this->fail('Expected tenant validation.');
            } catch (ValidationException) {}
        }
        $this->assertDatabaseCount('finance_obligations', 0);
        $this->assertNotSame($ownerA->id, $ownerB->id);
        $this->assertNotSame($organizationA->id, $organizationB->id);
    }

    /** @return array{User,\App\Models\Organization,\App\Models\Store,\App\Models\FinancialAccount,Supplier} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BANK-'.Str::upper(Str::random(5)), 'accepted_methods' => ['bank_transfer']]);
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = 'Supplier '.Str::random(4);
        $supplier->active = true;
        $supplier->save();
        return [$owner, $organization, $store, $account, $supplier];
    }

    private function obligation(User $owner, $organization, array $overrides = []): FinanceObligation
    {
        return app(ManageFinanceObligationAction::class)->create($owner, $organization, $this->obligationData($overrides));
    }

    private function obligationData(array $overrides = []): array
    {
        return array_replace(['store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur', 'reference' => null, 'due_date' => '2026-10-15', 'planned_amount' => '10000.0000', 'currency_code' => 'MAD', 'planned_payment_method' => 'bank_transfer', 'preferred_financial_account_id' => null, 'notes' => null], $overrides);
    }

    private function payment(User $owner, $organization, int $accountId, array $allocations, string $amount)
    {
        return app(RecordFinanceOutgoingPaymentAction::class)->execute($owner, $organization, [
            'store_id' => null, 'financial_account_id' => $accountId, 'supplier_id' => null, 'beneficiary_name' => 'Bénéficiaire',
            'payment_method' => 'bank_transfer', 'amount' => $amount, 'currency_code' => 'MAD', 'payment_date' => '2026-10-10',
            'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => collect($allocations)->map(fn ($row) => ['obligation_id' => $row[0], 'amount' => $row[1]])->all(),
        ]);
    }
}
