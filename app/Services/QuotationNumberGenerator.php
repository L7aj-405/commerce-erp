<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Allocate the next official Devis number for a calendar year.
 *
 * Format is `DEV-N/YYYY`, restarting at 1 each year. The counter row is created
 * on first use and locked FOR UPDATE for the remainder of the surrounding
 * issuance transaction, so concurrent issuances serialise and never collide.
 * This is a DEDICATED sequence — it never touches `invoice_sequences`.
 */
class QuotationNumberGenerator
{
    public function next(Organization $organization, int $year): string
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Quotation numbers may only be allocated inside an existing issuance transaction.');
        }

        DB::table('quotation_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storedNextNumber = (int) DB::table('quotation_sequences')
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->lockForUpdate()
            ->value('next_number');
        $number = max($storedNextNumber, $this->maxAllocatedNumber($organization, $year) + 1);

        DB::table('quotation_sequences')
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return 'DEV-'.$number.'/'.$year;
    }

    /** @return array{year: int, next_number: int, max_allocated_number: int} */
    public function settings(Organization $organization, int $year): array
    {
        $maxAllocated = $this->maxAllocatedNumber($organization, $year);
        $storedNextNumber = (int) (DB::table('quotation_sequences')
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
            DB::table('quotation_sequences')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'year' => $year,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('quotation_sequences')
                ->where('organization_id', $organization->getKey())
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $maxAllocated = $this->maxAllocatedNumber($organization, $year);
            if ($nextNumber <= $maxAllocated) {
                throw ValidationException::withMessages([
                    'quotation_next_number' => "Le prochain numéro doit être supérieur au dernier numéro de devis déjà attribué ({$maxAllocated}/{$year}).",
                ]);
            }

            DB::table('quotation_sequences')
                ->where('organization_id', $organization->getKey())
                ->where('year', $year)
                ->update(['next_number' => $nextNumber, 'updated_at' => now()]);
        });
    }

    private function maxAllocatedNumber(Organization $organization, int $year): int
    {
        return DB::table('quotations')
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('quotation_number')
            ->pluck('quotation_number')
            ->map(function (string $number) use ($year): int {
                if (! preg_match('/^DEV-(\d+)\/'.preg_quote((string) $year, '/').'(?:-R\d+)?$/', $number, $matches)) {
                    return 0;
                }

                return (int) $matches[1];
            })
            ->max() ?? 0;
    }
}
