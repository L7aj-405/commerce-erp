<?php

namespace App\Console\Commands;

use App\Services\Attention\QuotationAttentionDetector;
use Illuminate\Console\Command;

class ScanQuotationAttentionCommand extends Command
{
    protected $signature = 'attention:scan-quotations';

    protected $description = 'Detect / resolve quotation attention items (read-only on quotations; idempotent, safe to overlap)';

    public function handle(QuotationAttentionDetector $detector): int
    {
        $result = $detector->scanAll();
        $this->info("Quotation attention: {$result['organizations']} organization(s) scanned, {$result['failed']} failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
