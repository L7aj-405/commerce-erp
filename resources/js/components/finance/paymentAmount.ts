/*
 * Live validation for the supplier-payment amount (month-end situation). Display-only: exact BigInt decimals
 * (scale 4), no floats. The backend (RecordFinanceOutgoingPaymentAction) stays the source of truth and re-checks
 * the locked remaining balance, so a 422 is still surfaced when the balance changed after the form was opened.
 */
import { formatScaled, parseScaled } from './supplier-invoices/math.ts';

export type PaymentAmountProblem = 'required' | 'invalid' | 'notPositive' | 'exceeds';

export interface PaymentAmountSummary {
    planned: string;
    paid: string;
    /** remaining - committed (cheques/drafts), floored at zero: what the backend lets this payment use. */
    available: string;
    /** The entered amount as a normalized decimal, or null when it is not a valid number. */
    amount: string | null;
    /** available - amount; negative when over-paying, null when the amount is not a valid number. */
    after: string | null;
    problem: PaymentAmountProblem | null;
}

const asScaled = (value: string | null | undefined): bigint => parseScaled(value ?? '0') ?? 0n;

export function summarizePaymentAmount(
    obligation: { planned_amount: string; paid_amount: string; remaining_amount: string; committed_amount: string },
    enteredAmount: string,
): PaymentAmountSummary {
    const availableRaw = asScaled(obligation.remaining_amount) - asScaled(obligation.committed_amount);
    const available = availableRaw < 0n ? 0n : availableRaw;
    const base = {
        planned: formatScaled(asScaled(obligation.planned_amount)),
        paid: formatScaled(asScaled(obligation.paid_amount)),
        available: formatScaled(available),
    };

    if (enteredAmount.trim() === '') {
        return { ...base, amount: null, after: null, problem: 'required' };
    }
    const amount = parseScaled(enteredAmount);
    if (amount === null) {
        return { ...base, amount: null, after: null, problem: 'invalid' };
    }
    const after = available - amount;
    const problem: PaymentAmountProblem | null = amount <= 0n ? 'notPositive' : after < 0n ? 'exceeds' : null;

    return { ...base, amount: formatScaled(amount), after: formatScaled(after), problem };
}
