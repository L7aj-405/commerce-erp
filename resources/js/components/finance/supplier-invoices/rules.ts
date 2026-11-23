import { decimalEquals } from './math.ts';
import type { SupplierInvoiceMatchStatus, SupplierInvoiceVoidReasonCode } from './types';

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
