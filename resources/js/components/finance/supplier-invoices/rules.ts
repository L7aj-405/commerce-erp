import { decimalDiff, decimalEquals } from './math.ts';
import type { SupplierInvoiceMatchStatus, SupplierInvoiceStatus, SupplierInvoiceVoidReasonCode } from './types';

/** Client-side readiness checks for the dialogs. The server re-validates everything. */
export type ApprovalInput = {
    controlTotal: string;
    totalTtc: string;
    matchStatus: SupplierInvoiceMatchStatus;
    acknowledgeUnmatched: boolean;
    matchNote: string;
    warningCodes: string[];
    acknowledgedWarnings: string[];
};

export function approvalProblems(input: ApprovalInput): { controlTotal: boolean; matchAck: boolean; matchNote: boolean; warnings: boolean } {
    const needsMatchAck = input.matchStatus !== 'po_matched';
    return {
        controlTotal: !decimalEquals(input.controlTotal, input.totalTtc),
        matchAck: needsMatchAck && !input.acknowledgeUnmatched,
        matchNote: needsMatchAck && input.matchNote.trim() === '',
        warnings: input.warningCodes.some((code) => !input.acknowledgedWarnings.includes(code)),
    };
}

export function canApprove(input: ApprovalInput): boolean {
    return !Object.values(approvalProblems(input)).some(Boolean);
}

export function voidProblems(reasonCode: SupplierInvoiceVoidReasonCode | '', text: string): { reasonCode: boolean; text: boolean } {
    return { reasonCode: reasonCode === '', text: reasonCode === 'other' && text.trim() === '' };
}

export function canVoid(reasonCode: SupplierInvoiceVoidReasonCode | '', text: string): boolean {
    const p = voidProblems(reasonCode, text);
    return !p.reasonCode && !p.text;
}

/**
 * Server refusals that are not tied to a form field (e.g. `invoice` for "a payment exists", "period closed").
 * A dialog maps `fieldKeys` onto its inputs; everything else must still reach the user, never be dropped silently.
 */
export function unmappedErrors(errors: Record<string, string | undefined> | undefined, fieldKeys: readonly string[]): string[] {
    const messages = Object.entries(errors ?? {})
        .filter(([key, message]) => !fieldKeys.includes(key) && typeof message === 'string' && message.trim() !== '')
        .map(([, message]) => (message as string).trim());
    return [...new Set(messages)];
}

export type ControlTotalState = { kind: 'missing_required' | 'missing_not_recorded' | 'match' | 'mismatch'; diff: string | null };

/**
 * What the totals panel says about the paper control total. Only a draft invoice still has the
 * "required at approval" step ahead of it; a missing value on an approved/void invoice is a historical
 * fact (the value was checked at approval but never stored), not an action to take.
 */
export function controlTotalState(controlTotal: string | null | undefined, totalTtc: string, status: SupplierInvoiceStatus | undefined): ControlTotalState {
    const hasControl = controlTotal !== null && controlTotal !== undefined && controlTotal.trim() !== '';
    if (!hasControl) return { kind: status && status !== 'draft' ? 'missing_not_recorded' : 'missing_required', diff: null };
    return decimalEquals(controlTotal, totalTtc) ? { kind: 'match', diff: null } : { kind: 'mismatch', diff: decimalDiff(controlTotal, totalTtc) };
}
