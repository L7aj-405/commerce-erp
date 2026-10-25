<?php

namespace App\Enums;

/** W4.1 implements quotation attention only. */
enum AttentionType: string
{
    case QuotationSentUndecided = 'quotation_sent_undecided';
    case QuotationExpiringSoon = 'quotation_expiring_soon';
    case QuotationExpiredUndecided = 'quotation_expired_undecided';
    case QuotationFollowUpBacklog = 'quotation_followup_backlog';
}
