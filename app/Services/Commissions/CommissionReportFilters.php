<?php

namespace App\Services\Commissions;

use App\Models\CommissionEntry;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Validated, tenant-checked filter scope shared by the commission dashboard,
 * drill-down, own-view and exports. Dates always filter commission_entries
 * by `occurred_at` (operational / payout-period basis): a return received in
 * October reduces October, never the September sale period.
 */
final class CommissionReportFilters
{
    public const PERIODS = ['today', 'this_week', 'this_month', 'last_month', 'this_year', 'custom'];
    public const STATUSES = [CommissionEntry::STATUS_PENDING, CommissionEntry::STATUS_APPROVED, CommissionEntry::STATUS_PAID];
    public const ENTRY_TYPES = [
        CommissionEntry::TYPE_SALE, CommissionEntry::TYPE_RETURN_REVERSAL, CommissionEntry::TYPE_CORRECTION,
        CommissionEntry::TYPE_CANCELLATION, CommissionEntry::TYPE_MANUAL_ADJUSTMENT,
    ];

    public function __construct(
        public readonly string $period,
        public readonly string $from,
        public readonly string $to,
        public readonly ?Store $store = null,
        public readonly ?int $salespersonId = null,
        public readonly bool $unattributed = false,
        public readonly ?string $status = null,
        public readonly ?string $entryType = null,
    ) {}

    /**
     * Organization-wide filters. Store and salesperson ids are resolved inside
     * the organization only; a foreign id is a 404, never a silent widening.
     */
    public static function fromRequest(Request $request, Organization $organization, bool $allowSalesperson = true): self
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer'],
            'salesperson' => ['nullable', 'regex:/^(\d+|unattributed)$/'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'entry_type' => ['nullable', Rule::in(self::ENTRY_TYPES)],
        ]);

        [$period, $from, $to] = self::resolvePeriod($data['period'] ?? null, $data['from'] ?? null, $data['to'] ?? null);
        $store = empty($data['store_id']) ? null : Store::query()
            ->where('organization_id', $organization->getKey())->whereKey($data['store_id'])->firstOrFail();

        $salesperson = $allowSalesperson ? ($data['salesperson'] ?? null) : null;
        $unattributed = $salesperson === 'unattributed';
        $salespersonId = null;
        if ($salesperson !== null && ! $unattributed) {
            $salespersonId = self::resolveSalesperson($organization, (int) $salesperson);
        }

        return new self($period, $from, $to, $store, $salespersonId, $unattributed, $data['status'] ?? null, $data['entry_type'] ?? null);
    }

    /** @return array{0:string,1:string,2:string} */
    public static function resolvePeriod(?string $period, ?string $from, ?string $to): array
    {
        $today = CarbonImmutable::today();
        if ($period === null && ($from || $to)) {
            $period = 'custom';
        }
        $period ??= 'this_month';

        return match ($period) {
            'today' => ['today', $today->toDateString(), $today->toDateString()],
            'this_week' => ['this_week', $today->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString()],
            'last_month' => ['last_month', $today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'this_year' => ['this_year', $today->startOfYear()->toDateString(), $today->endOfYear()->toDateString()],
            'custom' => ['custom', $from ?? $today->startOfMonth()->toDateString(), $to ?? $from ?? $today->toDateString()],
            default => ['this_month', $today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()],
        };
    }

    /**
     * A salesperson is valid for this organization when they are (or were) a
     * member, or already appear on this organization's ledger (historical
     * attribution survives membership removal). Both checks are tenant-scoped.
     */
    public static function resolveSalesperson(Organization $organization, int $userId): int
    {
        $known = OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $userId)->exists()
            || CommissionEntry::query()->where('organization_id', $organization->getKey())->where('salesperson_id', $userId)->exists();
        abort_unless($known, 404);

        return $userId;
    }

    public function withSalesperson(?int $salespersonId, bool $unattributed = false): self
    {
        return new self($this->period, $this->from, $this->to, $this->store, $salespersonId, $unattributed, $this->status, $this->entryType);
    }

    public function fromTimestamp(): string
    {
        return $this->from.' 00:00:00';
    }

    public function toTimestamp(): string
    {
        return $this->to.' 23:59:59';
    }

    public function dayCount(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /** @return array<string, mixed> Serializable filter state for the UI and audit scope. */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'store_id' => $this->store?->getKey(),
            'salesperson' => $this->unattributed ? 'unattributed' : ($this->salespersonId === null ? null : (string) $this->salespersonId),
            'status' => $this->status,
            'entry_type' => $this->entryType,
        ];
    }
}
