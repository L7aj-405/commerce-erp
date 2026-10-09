<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\CommissionEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentValueFormatter;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * One aggregate notification per salesperson per approval / payment batch —
 * never one per ledger line, and never on entry creation. Each salesperson
 * only ever receives the count and net amount of their own entries.
 */
final class CommissionPayoutNotifier
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly DocumentValueFormatter $format,
    ) {}

    /** @param Collection<int, CommissionEntry> $entries */
    public function batch(Organization $organization, Collection $entries, string $status, int|string $batchId): int
    {
        $created = 0;
        $groups = $entries->filter(fn (CommissionEntry $entry) => $entry->salesperson_id !== null)->groupBy('salesperson_id');
        $users = User::query()->whereIn('id', $groups->keys())->get()->keyBy('id');

        foreach ($groups as $salespersonId => $own) {
            $user = $users->get($salespersonId);
            if (! $user) {
                continue;
            }
            $count = $own->count();
            $amount = $own->reduce(fn (string $sum, CommissionEntry $entry) => Decimal::add($sum, $entry->commission_amount), '0.0000');
            $money = $this->format->money($amount).' '.config('platform.currency_code', 'MAD');

            [$title, $message] = $status === CommissionEntry::STATUS_PAID
                ? ['Commissions payées', "{$money} de commissions ont été marqués comme payés ({$count} écriture(s))."]
                : ['Commissions approuvées', "{$count} commission(s) totalisant {$money} ont été approuvées."];

            $created += $this->publisher->publishToUser(
                $user,
                $organization,
                NotificationCategory::Finance,
                NotificationSeverity::Success,
                $title,
                $message,
                $user->hasPermission($organization, 'commissions.own.view') ? '/my-commissions' : null,
                'commission_batch',
                $batchId,
                $status,
                ['count' => $count, 'amount' => $amount],
            );
        }

        return $created;
    }
}
