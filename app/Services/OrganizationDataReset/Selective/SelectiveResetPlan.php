<?php

namespace App\Services\OrganizationDataReset\Selective;

use Carbon\CarbonImmutable;

/** Dry-run result of a selective reset; execution re-plans and compares `fingerprint`. */
final readonly class SelectiveResetPlan
{
    /**
     * @param array<string,mixed> $selection
     * @param array<string, list<int>> $delete table => ids to delete
     * @param array<string, list<int>> $unlink "table.column" => ids of retained rows whose link is cleared
     * @param list<int> $release active reservation ids released (balance.reserved decremented) then deleted
     * @param array<string, int> $selected root table => explicitly selected rows
     * @param list<array<string,mixed>> $blockers
     * @param array<string, array{delete: array<string,int>, unlink: array<string,int>}> $breakdown category => counts
     * @param array<string, list<string>> $samples table => first references
     * @param array<string, int> $protected protected table => rows kept
     */
    public function __construct(
        public int $organizationId,
        public array $selection,
        public array $delete,
        public array $unlink,
        public array $release,
        public array $selected,
        public array $blockers,
        public array $breakdown,
        public array $samples,
        public array $protected,
        public string $fingerprint,
        public CarbonImmutable $previewedAt,
    ) {}

    public function isExecutable(): bool
    {
        return $this->blockers === [] && $this->deleteTotal() > 0;
    }

    public function deleteTotal(): int
    {
        return array_sum(array_map('count', $this->delete));
    }

    public function unlinkTotal(): int
    {
        return array_sum(array_map('count', $this->unlink));
    }

    public function selectedTotal(): int
    {
        return array_sum($this->selected);
    }
}
