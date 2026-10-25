<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Models\AttentionItem;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * The domain detectors of the Attention Engine (W4.1 quotations, W4.4
 * invoices). Shared services (notifier, escalator) ask "is this item still
 * at this severity?" here, so each domain's rules exist exactly once.
 */
final class AttentionDetectors
{
    public function __construct(
        private readonly QuotationAttentionDetector $quotations,
        private readonly InvoiceAttentionDetector $invoices,
    ) {}

    public function currentSeverity(Organization $organization, AttentionItem $item): ?AttentionSeverity
    {
        return match ($item->subject_type) {
            AttentionItem::SUBJECT_QUOTATION => $this->quotations->currentSeverity($organization, $item),
            AttentionItem::SUBJECT_INVOICE => $this->invoices->currentSeverity($organization, $item),
            default => null,
        };
    }

    /** Run every domain scan for one organization (each isolated; a failed domain never resolves its episodes). @return array<string, array<string, int>> */
    public function scanOrganization(Organization $organization, ?CarbonImmutable $now = null): array
    {
        return [
            AttentionItem::SUBJECT_QUOTATION => $this->quotations->scanOrganization($organization, $now),
            AttentionItem::SUBJECT_INVOICE => $this->invoices->scanOrganization($organization, $now),
        ];
    }
}
