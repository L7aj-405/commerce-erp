<?php

namespace App\Services\Attention;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The scheduled Attention Engine pipeline (attention:scan), per active
 * organization, on fresh state: domain detection (quotations, sales orders,
 * invoices) → rollout baseline (W4.5.1) → ownership repair → critical
 * notifications (to the owner) → escalation. A domain whose baseline is still
 * pending (first scan failed) is kept away from notification and escalation.
 * Each concern stays in its own service; an organization that fails never
 * stops the others. Read-only on business documents.
 */
final class AttentionScanner
{
    public function __construct(
        private readonly AttentionDetectors $detectors,
        private readonly AttentionOwnership $ownership,
        private readonly AttentionNotifier $notifier,
        private readonly AttentionEscalator $escalator,
        private readonly AttentionBaselines $baselines,
    ) {}

    /** @return array{organizations: int, notified: int, escalated: int, failed: int} */
    public function scanAll(?CarbonImmutable $now = null): array
    {
        $result = ['organizations' => 0, 'notified' => 0, 'escalated' => 0, 'failed' => 0];
        Organization::query()->where('status', 'active')->select(['id', 'name', 'status', 'settings'])
            ->chunkById(100, function ($organizations) use ($now, &$result) {
                foreach ($organizations as $organization) {
                    try {
                        $outcome = $this->scanOrganization($organization, $now ?? CarbonImmutable::now('UTC'));
                        $result['notified'] += $outcome['notified'];
                        $result['escalated'] += $outcome['escalated'];
                        $result['organizations']++;
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        report($exception);
                    }
                }
            });

        return $result;
    }

    /** @return array{notified: int, escalated: int} */
    public function scanOrganization(Organization $organization, CarbonImmutable $now): array
    {
        $pending = array_values(array_filter(AttentionBaselines::SUBJECTS, fn (string $subject) => $this->baselines->isPending($organization, $subject)));
        $results = $this->detectors->scanOrganization($organization, $now);
        foreach ($pending as $subject) {
            // Only a complete first scan establishes the baseline (historical items become notified-without-delivery / exempt).
            if (($results[$subject]['failed'] ?? 1) === 0) {
                $this->baselines->establish($organization, $subject, $now);
            }
        }
        $stillPending = array_values(array_filter($pending, fn (string $subject) => $this->baselines->isPending($organization, $subject)));
        $this->ownership->repairOrganization($organization);

        return [
            'notified' => $this->notifier->notifyOrganization($organization, $now, $stillPending)['sent'],
            'escalated' => $this->escalator->escalateOrganization($organization, $now, $stillPending)['escalated'],
        ];
    }
}
