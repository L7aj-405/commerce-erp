<?php

namespace App\Enums;

use App\Models\AttentionItem;

/** W4.1 quotation attention; W4.4 invoice / payment attention; W4.5 sales order receivables; P1 sales order procurement. */
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

    case SalesOrderPaymentDueSoon = 'sales_order_payment_due_soon';
    case SalesOrderUnpaidOverdue = 'sales_order_unpaid_overdue';
    case SalesOrderPartialOverdue = 'sales_order_partial_overdue';
    case SalesOrderPaymentFollowUpBacklog = 'sales_order_payment_followup_backlog';
    case SalesOrderPaidNotInvoiced = 'sales_order_paid_not_invoiced';
    case SalesOrderProcurementRequired = 'sales_order_procurement_required';
    case SalesOrderProcurementUnresolved = 'sales_order_procurement_unresolved';

    public function subjectType(): string
    {
        return match (true) {
            str_starts_with($this->value, 'invoice_') => AttentionItem::SUBJECT_INVOICE,
            str_starts_with($this->value, 'sales_order_') => AttentionItem::SUBJECT_SALES_ORDER,
            default => AttentionItem::SUBJECT_QUOTATION,
        };
    }

    public function isBacklog(): bool
    {
        return in_array($this, [self::QuotationFollowUpBacklog, self::InvoicePaymentFollowUpBacklog, self::SalesOrderPaymentFollowUpBacklog], true);
    }
}
