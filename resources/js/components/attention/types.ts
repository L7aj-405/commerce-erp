export type AttentionItem = {
    id: number;
    type: 'quotation_sent_undecided' | 'quotation_expiring_soon' | 'quotation_expired_undecided' | 'quotation_followup_backlog'
        | 'invoice_due_soon' | 'invoice_overdue_unpaid' | 'invoice_overdue_partial' | 'invoice_payment_followup_backlog'
        | 'sales_order_payment_due_soon' | 'sales_order_unpaid_overdue' | 'sales_order_partial_overdue' | 'sales_order_payment_followup_backlog' | 'sales_order_paid_not_invoiced';
    subject_type: 'quotation' | 'sales_order' | 'invoice';
    severity: 'info' | 'warning' | 'critical';
    status: 'open' | 'snoozed' | 'dismissed' | 'resolved';
    title: string;
    summary: string;
    i18n: { title: string; message: string; params: Record<string, string> } | null;
    quotation: { id: number; number: string | null; can_open: boolean } | null;
    invoice: { id: number; number: string | null; can_open: boolean } | null;
    sales_order: { id: number; number: string | null; can_open: boolean } | null;
    customer: { id: number; name: string } | null;
    detected_at: string;
    snoozed_until: string | null;
    dismissed_at: string | null;
    dismiss_note: string | null;
    resolved_at: string | null;
    follow_up_count: number;
    notified_at: string | null;
    assignee: { id: number; name: string } | null;
    store_id: number | null;
    escalated_at: string | null;
    can_claim: boolean;
    can_assign: boolean;
    is_active: boolean;
    can_act: boolean;
    can_create_follow_up: boolean;
    suggestion: { title: string; due_date: string };
    version: number;
};

/** Managers only: valid owners with the stores they can see. */
export type AttentionOwner = { id: number; name: string; store_ids: number[]; subjects: string[] };
