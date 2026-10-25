<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Attention\AttentionNotifier;
use App\Services\Attention\QuotationAttentionDetector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ScanQuotationAttentionCommand extends Command
{
    protected $signature = 'attention:scan-quotations';

    protected $description = 'Detect / resolve quotation attention items, then notify new critical episodes (read-only on quotations; idempotent, safe to overlap)';

    public function handle(QuotationAttentionDetector $detector, AttentionNotifier $notifier): int
    {
        $sent = 0;
        $result = $detector->scanAll(afterOrganization: function (Organization $organization, CarbonImmutable $now) use ($notifier, &$sent) {
            $sent += $notifier->notifyOrganization($organization, $now)['sent'];
        });
        $this->info("Quotation attention: {$result['organizations']} organization(s) scanned, {$sent} critical notification(s), {$result['failed']} failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
