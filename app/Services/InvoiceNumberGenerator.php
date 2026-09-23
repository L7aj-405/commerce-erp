<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class InvoiceNumberGenerator
{
    /**
     * Allocate the next official invoice number for a calendar year.
     *
     * Format is `N/YYYY`, restarting at 1 each year. The counter row is created
     * on first use and locked FOR UPDATE for the remainder of the surrounding
     * issuance transaction, so concurrent issuances serialise and never collide.
     * Allocation outside a transaction is refused — a rolled-back issuance must
     * not consume a number.
     */
    public function next(Organization $organization, int $year): string
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Invoice numbers may only be allocated inside an existing issuance transaction.');
        }

        DB::table('invoice_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storedNextNumber = (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->lockForUpdate()
            ->value('next_number');
        $number = max($storedNextNumber, $this->maxAllocatedNumber($organization, $year) + 1);

        DB::table('invoice_sequences')
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return $number.'/'.$year;
    }

    /** @return array{year: int, next_number: int, max_allocated_number: int} */
    public function settings(Organization $organization, int $year): array
    {
        $maxAllocated = $this->maxAllocatedNumber($organization, $year);
        $storedNextNumber = (int) (DB::table('invoice_sequences')
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->value('next_number') ?? 1);

        return [
            'year' => $year,
            'next_number' => max($storedNextNumber, $maxAllocated + 1),
            'max_allocated_number' => $maxAllocated,
        ];
    }

    public function configureNextNumber(Organization $organization, int $year, int $nextNumber): void
    {
        DB::transaction(function () use ($organization, $year, $nextNumber) {
            DB::table('invoice_sequences')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'year' => $year,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('invoice_sequences')
                ->where('organization_id', $organization->getKey())
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $maxAllocated = $this->maxAllocatedNumber($organization, $year);
            if ($nextNumber <= $maxAllocated) {
                throw ValidationException::withMessages([
                    'invoice_next_number' => "Le prochain numéro doit être supérieur au dernier numéro ERP déjà attribué ({$maxAllocated}/{$year}).",
                ]);
            }

            DB::table('invoice_sequences')
                ->where('organization_id', $organization->getKey())
                ->where('year', $year)
                ->update(['next_number' => $nextNumber, 'updated_at' => now()]);
        });
    }

    private function maxAllocatedNumber(Organization $organization, int $year): int
    {
        return DB::table('invoice_families')
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('canonical_invoice_number')
            ->pluck('canonical_invoice_number')
            ->map(function (string $number) use ($year): int {
                if (! preg_match('/^(\d+)\/'.preg_quote((string) $year, '/').'$/', $number, $matches)) {
                    return 0;
                }

                return (int) $matches[1];
            })
            ->max() ?? 0;
    }
}
