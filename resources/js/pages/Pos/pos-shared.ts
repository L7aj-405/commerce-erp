import type { Customer } from './types';

export type Account = { id: number; name: string; code: string; type: string; currency_code: string };

/**
 * Scoped POS pending signals. Every field is derived from the `usePendingKeys`
 * map in `Index.tsx`, so a control can show precise loading/disabled state
 * without a single global `busy` freezing the whole point of sale.
 */
export type PosPending = {
    /** A quantity or discount write is in flight for this cart line. */
    line: (lineId: number) => boolean;
    /** This cart line is being removed. */
    removing: (lineId: number) => boolean;
    /** The global (order-level) discount is being written. */
    globalDiscount: boolean;
    /** Authoritative totals are being recalculated by the server. */
    recalculating: boolean;
    /** The current sale is being put on hold. */
    hold: boolean;
};

export type PaymentMethod = 'cash' | 'card' | 'bank_transfer' | 'cheque';

/**
 * POS settlement choice. This drives the payment UI and the payload sent to
 * the server — it is never sent itself: `full`/`partial` submit one or more
 * real `payments` rows, `deferred` submits an empty `payments` array so no
 * Payment record is ever created for money that has not moved yet.
 */
export type SettlementMode = 'full' | 'partial' | 'deferred';

export const settlementLabels: Record<SettlementMode, string> = {
    full: 'Paiement comptant',
    partial: 'Paiement partiel',
    deferred: 'Paiement ultérieur',
};

export type PaymentDraft = {
    method: PaymentMethod | '';
    financial_account_id: number | '';
    amount: string;
    cash_received: string;
    reference: string;
    amountTouched: boolean;
    cashReceivedTouched: boolean;
};

export type CheckoutState = {
    fulfillment_mode: 'pickup' | 'delivery';
    shipping_fee: string;
    delivery_address: string;
    delivery_phone: string;
    delivery_notes: string;
};

export type CustomerForm = {
    display_name: string;
    company_name: string;
    contact_name: string;
    phone: string;
    email: string;
    tax_identifier: string;
    billing_address: string;
};

export type CompletePayload = {
    fulfillment_mode: 'pickup' | 'delivery';
    payments: Array<{ method: string; financial_account_id: number; amount: string; cash_received?: string | null; reference?: string | null }>;
};

export type ReviewPayment = {
    method: PaymentMethod;
    label: string;
    amount: string;
    cash_received: string | null;
    reference: string | null;
    financial_account_id: number;
    account_label: string | null;
};

export type ReviewData = {
    fulfillment_mode: 'pickup' | 'delivery';
    totals: {
        subtotal_excl_tax: string;
        discount: string;
        net_excl_tax: string;
        tax_total: string;
        shipping: string;
        total: string;
    };
    paid: string;
    remaining: string;
    payments: ReviewPayment[];
    customer: Customer | null;
    delivery: { address: string; phone: string; notes: string } | null;
    requires_replenishment: boolean;
    remote_required: string;
    print: boolean;
};

export const paymentLabels: Record<PaymentMethod, string> = {
    cash: 'Espèces',
    card: 'TPE / Carte',
    bank_transfer: 'Virement',
    cheque: 'Chèque',
};

export const referenceLabels: Record<PaymentMethod, string> = {
    cash: '',
    card: 'Référence transaction / ticket TPE',
    bank_transfer: 'Référence du virement',
    cheque: 'Numéro du chèque',
};

const accountTypes: Record<PaymentMethod, string[]> = {
    cash: ['cash'],
    card: ['card_clearing'],
    bank_transfer: ['bank'],
    cheque: ['cheque_clearing'],
};

export const blankCustomerForm = (): CustomerForm => ({
    display_name: '',
    company_name: '',
    contact_name: '',
    phone: '',
    email: '',
    tax_identifier: '',
    billing_address: '',
});

export function normalizeAmount(value: string): string {
    const numeric = Number(value);
    if (!Number.isFinite(numeric) || numeric <= 0) {
        return '';
    }

    return numeric.toFixed(4);
}

export function positiveAmount(value: string): number {
    const numeric = Number(value);

    return Number.isFinite(numeric) && numeric > 0 ? numeric : 0;
}

export function customerTypeFor(customer: Customer | null): 'individual' | 'business' {
    return customer?.type === 'business' ? 'business' : 'individual';
}

export function customerFormFrom(customer: Customer | null): CustomerForm {
    if (!customer) {
        return blankCustomerForm();
    }

    return {
        display_name: customer.type === 'business' ? '' : customer.display_name,
        company_name: customer.company_name ?? '',
        contact_name: customer.type === 'business' ? customer.display_name : '',
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        tax_identifier: customer.tax_identifier ?? '',
        billing_address: customer.billing_address ?? '',
    };
}

export function compatibleAccounts(method: PaymentMethod | '', financialAccounts: Account[]): Account[] {
    if (!method) {
        return [];
    }

    return financialAccounts.filter((account) => accountTypes[method].includes(account.type));
}

export function firstCompatibleAccountId(method: PaymentMethod | '', financialAccounts: Account[]): number | '' {
    return compatibleAccounts(method, financialAccounts)[0]?.id ?? '';
}

export function buildInitialPayment(total: string, financialAccounts: Account[]): PaymentDraft {
    return {
        method: 'cash',
        financial_account_id: firstCompatibleAccountId('cash', financialAccounts),
        amount: normalizeAmount(total),
        cash_received: normalizeAmount(total),
        reference: '',
        amountTouched: false,
        cashReceivedTouched: false,
    };
}

export function buildEmptyPayment(): PaymentDraft {
    return {
        method: '',
        financial_account_id: '',
        amount: '',
        cash_received: '',
        reference: '',
        amountTouched: false,
        cashReceivedTouched: false,
    };
}
