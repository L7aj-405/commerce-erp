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
