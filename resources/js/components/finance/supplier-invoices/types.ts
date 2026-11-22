/*
 * Inertia prop contracts for supplier invoices (routes finance.supplier-invoices.*).
 * Money is ALWAYS a decimal string (never a float); the browser only sums strings
 * for display feedback, the server remains the authority.
 */
export type SupplierInvoiceStatus = 'draft' | 'approved' | 'void';
export type SupplierInvoiceMatchStatus = 'no_po' | 'po_partial' | 'po_matched' | 'variance';
export type SupplierInvoiceMatchFlag = 'not_linked' | 'over_invoiced' | 'not_received' | 'po_price_missing' | 'price_variance';
export type SupplierInvoiceVoidReasonCode = 'duplicate_entry' | 'wrong_supplier' | 'wrong_data_entry' | 'other';
export const VOID_REASON_CODES: readonly SupplierInvoiceVoidReasonCode[] = ['duplicate_entry', 'wrong_supplier', 'wrong_data_entry', 'other'];

export type Paginator<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page?: number;
    last_page?: number;
    total?: number;
};

export type SupplierInvoiceListRow = {
    id: number;
    internal_number: string;
    invoice_number: string;
    invoice_date: string;
    status: SupplierInvoiceStatus;
    supplier: { id: number; name: string; ice: string | null };
    store: { id: number; name: string; code: string };
    currency_code: string;
    total_ttc: string;
    match_status: SupplierInvoiceMatchStatus | null;
    has_attachments: boolean;
    warnings_count: number;
};

export type SupplierInvoiceIndexProps = {
    invoices: Paginator<SupplierInvoiceListRow>;
    filters: { status: string | null; supplier_id: number | string | null; store_id: number | string | null; search: string | null };
    stores: { id: number; name: string; code?: string }[];
    suppliers: { id: number; name: string }[];
    can: { create: boolean; approve: boolean; void: boolean };
};

export type SupplierInvoiceLine = {
    id: number;
    position: number;
    cost_nature: 'inventory' | 'expense';
    description: string;
    quantity: string;
    unit_price_ht: string;
    tax_rate_percent: string;
    tax_name: string | null;
    line_ht: string;
    line_tva: string;
    line_ttc: string;
    product_variant: { id: number; name: string; sku: string | null } | null;
    purchase_order_line_id: number | null;
};

export type SupplierInvoiceInstallment = {
    id: number;
    position: number;
    due_date: string;
    amount: string;
    planned_payment_method: string | null;
    preferred_financial_account: { id: number; name: string } | null;
    obligation: { id: number; number: string; planned: string; paid: string; remaining: string; status: string } | null;
};

export type SupplierInvoiceAttachment = {
    id: number;
    original_name: string;
    mime: string;
    size_bytes: number;
    url: string;
};

export type SupplierInvoiceHeader = {
    id: number;
    internal_number: string;
    invoice_number: string;
    invoice_date: string;
    status: SupplierInvoiceStatus;
    currency_code: string;
    subtotal_ht: string;
    total_tva: string;
    rounding_adjustment: string;
    total_ttc: string;
    control_total_ttc: string | null;
    notes: string | null;
    supplier: { id: number; name: string; ice: string | null };
    store: { id: number; name: string; code: string };
    row_version: number;
    lines: SupplierInvoiceLine[];
    installments: SupplierInvoiceInstallment[];
    attachments: SupplierInvoiceAttachment[];
    approval: { approved_at: string; approved_by: string | null; match_status_at_approval: SupplierInvoiceMatchStatus | null; match_note: string | null } | null;
    void: { voided_at: string; voided_by: string | null; reason_code: SupplierInvoiceVoidReasonCode; reason: string | null } | null;
};

export type SupplierInvoiceMatching = {
    status: SupplierInvoiceMatchStatus;
    lines: { line_id: number; flags: SupplierInvoiceMatchFlag[] }[];
};

/** Soft (non-blocking) warning; `code` identifies the detector, `message` is already French. */
export type SupplierInvoiceWarning = { code: string; message: string };

export type SupplierInvoiceShowProps = {
    invoice: SupplierInvoiceHeader;
    matching: SupplierInvoiceMatching;
    warnings: SupplierInvoiceWarning[];
    can: { edit: boolean; approve: boolean; void: boolean; attach: boolean };
};

export type SupplierInvoiceFormProps = {
    invoice: SupplierInvoiceHeader | null;
    suppliers: { id: number; name: string; ice?: string | null; default_payment_term_days?: number | null; default_currency_code?: string | null }[];
    stores: { id: number; name: string; code?: string }[];
    taxRates: { id: number; name: string; rate: string }[];
    purchaseOrders: { id: number; po_number: string; supplier_id: number; store_id: number }[];
    accounts: { id: number; name: string }[];
    settings: { rounding_tolerance: string; require_distinct_approver: boolean; money_scale: number };
    row_version: number;
};

/** Editable installment row (client-side state). `key` is a stable React key. */
export type InstallmentDraft = { key: string; due_date: string; amount: string };
