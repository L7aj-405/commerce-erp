<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Attention\AttentionEscalator;
use App\Services\Attention\AttentionNotifier;
use App\Services\Attention\AttentionOwnership;
use App\Services\Attention\QuotationAttentionDetector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ScanQuotationAttentionCommand extends Command
{
    protected $signature = 'attention:scan-quotations';

    protected $description = 'Detect / resolve quotation attention, repair ownership, notify new critical episodes, escalate (read-only on quotations; idempotent, safe to overlap)';

    public function handle(QuotationAttentionDetector $detector, AttentionOwnership $ownership, AttentionNotifier $notifier, AttentionEscalator $escalator): int
    {
        $sent = $escalated = 0;
        // Per organization, on fresh state: detect → owners → critical notification (to the owner) → escalation.
        $result = $detector->scanAll(afterOrganization: function (Organization $organization, CarbonImmutable $now) use ($ownership, $notifier, $escalator, &$sent, &$escalated) {
            $ownership->repairOrganization($organization);
            $sent += $notifier->notifyOrganization($organization, $now)['sent'];
            $escalated += $escalator->escalateOrganization($organization, $now)['escalated'];
        });
        $this->info("Quotation attention: {$result['organizations']} organization(s) scanned, {$sent} critical notification(s), {$escalated} escalation(s), {$result['failed']} failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
