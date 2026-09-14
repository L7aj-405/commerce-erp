<?php

namespace Tests\Support;

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

abstract class PaymentTestCase extends SalesTestCase
{
    protected function createFinancialAccount(Organization $organization, array $overrides = []): FinancialAccount
    {
        $type = $overrides['type'] ?? 'cash';
        $account = new FinancialAccount;
        $account->organization_id = $organization->getKey();
        $account->name = $overrides['name'] ?? 'Cash Drawer';
        $account->code = $overrides['code'] ?? 'CASH';
        $account->type = $type;
        $account->status = $overrides['status'] ?? 'active';
        $account->currency_code = $overrides['currency_code'] ?? 'MAD';
        $account->notes = $overrides['notes'] ?? null;
        $account->accepted_methods = $overrides['accepted_methods'] ?? $this->defaultAcceptedMethodsForType($type);
        $account->save();

        return $account;
    }

    /** @return array{User, Organization, Store, SalesOrder, FinancialAccount} */
    protected function paymentFixture(string $total = '100.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => $total]);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $account = $this->createFinancialAccount($organization);

        return [$owner, $organization, $store, $order, $account];
    }

    protected function recordPayment(User $actor, SalesOrder $order, FinancialAccount $account, string $amount, array $overrides = []): Payment
    {
        return app(RecordPaymentAction::class)->execute($actor, $order, array_replace([
            'method' => 'cash',
            'financial_account_id' => $account->getKey(),
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'reference' => null,
            'external_reference' => null,
            'notes' => null,
        ], $overrides), $overrides['client_operation_id'] ?? (string) Str::uuid(), $overrides['operation_sequence'] ?? 1);
    }
}
