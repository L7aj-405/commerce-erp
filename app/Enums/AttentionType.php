<?php

namespace App\Enums;

use App\Models\AttentionItem;

/** W4.1 quotation attention; W4.4 invoice / payment attention. */
enum AttentionType: string
{
    case QuotationSentUndecided = 'quotation_sent_undecided';
    case QuotationExpiringSoon = 'quotation_expiring_soon';
    case QuotationExpiredUndecided = 'quotation_expired_undecided';
    case QuotationFollowUpBacklog = 'quotation_followup_backlog';

    case InvoiceDueSoon = 'invoice_due_soon';
    case InvoiceOverdueUnpaid = 'invoice_overdue_unpaid';
    case InvoiceOverduePartial = 'invoice_overdue_partial';
    case InvoicePaymentFollowUpBacklog = 'invoice_payment_followup_backlog';

    public function subjectType(): string
    {
        return str_starts_with($this->value, 'invoice_') ? AttentionItem::SUBJECT_INVOICE : AttentionItem::SUBJECT_QUOTATION;
    }

    public function isBacklog(): bool
    {
        return $this === self::QuotationFollowUpBacklog || $this === self::InvoicePaymentFollowUpBacklog;
    }
}
