<?php

namespace App\Console\Commands;

use App\Services\Attention\AttentionScanner;
use Illuminate\Console\Command;

/** W4.4: the Attention Engine scan for every domain (quotations, invoices); the W4.1 name stays as an alias. */
class ScanAttentionCommand extends Command
{
    protected $signature = 'attention:scan';

    protected $aliases = ['attention:scan-quotations'];

    protected $description = 'Detect / resolve quotation and invoice attention, repair ownership, notify new critical episodes, escalate (read-only on documents; idempotent, safe to overlap)';

    public function handle(AttentionScanner $scanner): int
    {
        $result = $scanner->scanAll();
        $this->info("Attention: {$result['organizations']} organization(s) scanned, {$result['notified']} critical notification(s), {$result['escalated']} escalation(s), {$result['failed']} failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
