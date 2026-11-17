import type { PaymentDraft, SettlementMode } from './pos-shared';

/**
 * Single source of truth for the cashier's payment choice on a POS draft.
 *
 * The checkout panel is unmounted whenever the cashier goes back to the cart (or the layout
 * switches), so payment state kept only in component state silently fell back to the default
 * "full payment / cash". This store outlives the panel: it is keyed by draft (order) id, kept in
 * memory and mirrored to sessionStorage so a reload / draft resume restores the same choice.
 * Nothing here is sent to the server: no order, payment or stock operation is created.
 */
export type PaymentSnapshot = { settlementMode: SettlementMode; payments: PaymentDraft[] };

const PREFIX = 'pos:payment-state:';
const memory = new Map<number, PaymentSnapshot>();
const MODES: SettlementMode[] = ['full', 'partial', 'deferred'];

function isSnapshot(value: unknown): value is PaymentSnapshot {
    if (typeof value !== 'object' || value === null) return false;
    const candidate = value as { settlementMode?: unknown; payments?: unknown };

    return MODES.includes(candidate.settlementMode as SettlementMode)
        && Array.isArray(candidate.payments)
        && candidate.payments.every((payment) => typeof payment === 'object' && payment !== null && 'method' in payment && 'amount' in payment);
}

export function loadPaymentSnapshot(orderId: number): PaymentSnapshot | null {
    const cached = memory.get(orderId);
    if (cached) return cached;
    try {
        const raw = window.sessionStorage.getItem(PREFIX + orderId);
        if (!raw) return null;
        const parsed: unknown = JSON.parse(raw);
        if (!isSnapshot(parsed)) return null;
        memory.set(orderId, parsed);

        return parsed;
    } catch {
        return null;
    }
}

export function savePaymentSnapshot(orderId: number, snapshot: PaymentSnapshot): void {
    memory.set(orderId, snapshot);
    try {
        window.sessionStorage.setItem(PREFIX + orderId, JSON.stringify(snapshot));
    } catch {
        /* storage unavailable: the in-memory copy still survives panel remounts */
    }
}

/** Forget every stored payment choice (a sale was completed or the cart was discarded). */
export function clearPaymentSnapshots(): void {
    memory.clear();
    try {
        for (const key of Object.keys(window.sessionStorage)) {
            if (key.startsWith(PREFIX)) window.sessionStorage.removeItem(key);
        }
    } catch {
        /* noop */
    }
}
