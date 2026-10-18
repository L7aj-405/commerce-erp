<?php

namespace App\Services\Finance;

use App\Models\Organization;
use LogicException;
use Illuminate\Support\Facades\DB;

class FinanceNumberGenerator
{
    public function nextObligation(Organization $organization, int $year): string
    {
        return $this->next('finance_obligation_sequences', 'OBL', $organization, $year);
    }

    public function nextOutgoingPayment(Organization $organization, int $year): string
    {
        return $this->next('finance_outgoing_payment_sequences', 'DSP', $organization, $year);
    }

    public function nextRecurringTemplate(Organization $organization, int $year): string
    {
        return $this->next('finance_recurring_template_sequences', 'CRF', $organization, $year);
    }

    public function nextSupplierInstallmentPlan(Organization $organization, int $year): string
    {
        return $this->next('finance_supplier_plan_sequences', 'ECH', $organization, $year);
    }

    public function nextPaymentInstrument(Organization $organization, int $year, string $type): string
    {
        return $this->next('finance_instrument_sequences', $type === 'cheque' ? 'CHQ' : 'TRT', $organization, $year);
    }

    private function next(string $table, string $prefix, Organization $organization, int $year): string
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Finance numbers may only be allocated inside the owning transaction.');
        }

        $connection->table($table)->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = $connection->table($table)
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            throw new LogicException('Unable to allocate the Finance sequence.');
        }

        $number = (int) $sequence->next_number;
        $connection->table($table)
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return sprintf('%s-%d-%06d', $prefix, $year, $number);
    }
}
