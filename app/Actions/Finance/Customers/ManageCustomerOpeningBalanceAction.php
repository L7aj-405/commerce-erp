<?php

namespace App\Actions\Finance\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceClosedPeriodGuard;
use App\Services\Sandbox\SandboxContext;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled customer opening balance (historical debt or credit carried over from before the ERP).
 *
 * It is a statement/exposure line ONLY: it creates no sale, no VAT, no invoice and no bank/cash receipt, and it never
 * enters sales, billing, collection or financial-account figures. A mistake is corrected by REVERSING the entry (status
 * change with reason), never by deleting it; both events are audited. Amount is signed: positive = the customer owes us.
 */
class ManageCustomerOpeningBalanceAction
{
    public function __construct(private readonly AuditLogger $audit, private readonly FinanceClosedPeriodGuard $periods) {}

    /** @param array{effective_date:string, amount:string, currency_code:string, reference:string, reason:string} $data */
    public function record(User $actor, Customer $customer, array $data): int
    {
        $this->authorize($actor, $customer);
        $amount = Decimal::normalize($data['amount']);
        if (Decimal::compare($amount, '0') === 0) {
            throw ValidationException::withMessages(['amount' => 'Le montant d’un solde d’ouverture ne peut pas être nul.']);
        }
        foreach (['reference', 'reason'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw ValidationException::withMessages([$field => 'Ce champ est obligatoire.']);
            }
        }
        $currency = strtoupper($data['currency_code']);

        return DB::transaction(function () use ($actor, $customer, $data, $amount, $currency) {
            $this->periods->assertOpen($customer->organization_id, $data['effective_date'], 'effective_date');
            $id = DB::table('customer_opening_balances')->insertGetId([
                'organization_id' => $customer->organization_id, 'customer_id' => $customer->getKey(), 'effective_date' => $data['effective_date'],
                'amount' => $amount, 'currency_code' => $currency, 'reference' => trim($data['reference']), 'reason' => trim($data['reason']),
                'status' => 'posted', 'created_by_user_id' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('customer_opening_balance.recorded', $actor, $customer->organization, null, $customer, newValues: [
                'opening_balance_id' => $id, 'effective_date' => $data['effective_date'], 'amount' => $amount, 'currency_code' => $currency,
                'reference' => trim($data['reference']), 'reason' => trim($data['reason']),
            ]);

            return $id;
        });
    }

    public function reverse(User $actor, Customer $customer, int $openingBalanceId, string $reason): void
    {
        $this->authorize($actor, $customer);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif de l’inversion est obligatoire.']);
        }
        DB::transaction(function () use ($actor, $customer, $openingBalanceId, $reason) {
            $row = DB::table('customer_opening_balances')->where('organization_id', $customer->organization_id)->where('customer_id', $customer->getKey())
                ->where('id', $openingBalanceId)->lockForUpdate()->first();
            abort_if($row === null, 404);
            if ($row->status !== 'posted') {
                throw ValidationException::withMessages(['opening_balance' => 'Ce solde d’ouverture est déjà inversé.']);
            }
            if (DB::table('customer_opening_balance_settlements')->where('organization_id', $customer->organization_id)->where('opening_balance_id', $openingBalanceId)->whereNull('released_date')->exists()) {
                throw ValidationException::withMessages(['opening_balance' => 'Des avances sont affectées à ce solde d’ouverture : retirez d’abord ces affectations.']);
            }
            $this->periods->assertOpen($customer->organization_id, $row->effective_date, 'effective_date');
            DB::table('customer_opening_balances')->where('id', $openingBalanceId)->update([
                'status' => 'reversed', 'reversed_at' => now(), 'reversed_by_user_id' => $actor->getKey(), 'reversal_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $this->audit->record('customer_opening_balance.reversed', $actor, $customer->organization, null, $customer,
                oldValues: ['status' => 'posted', 'amount' => (string) $row->amount], newValues: ['opening_balance_id' => $openingBalanceId, 'status' => 'reversed', 'reason' => trim($reason)]);
        });
    }

    private function authorize(User $actor, Customer $customer): void
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        abort_unless($actor->active_organization_id === $customer->organization_id
            && $actor->hasPermission($customer->organization_id, 'finance.customers.opening_balance.manage'), 403);
    }
}
