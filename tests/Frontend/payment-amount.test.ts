// Run: node --experimental-strip-types --test tests/Frontend/payment-amount.test.ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { summarizePaymentAmount } from '../../resources/js/components/finance/paymentAmount.ts';

const obligation = { planned_amount: '120.0000', paid_amount: '0.0000', remaining_amount: '120.0000', committed_amount: '0.0000' };

test('121 against 120 is excessive and leaves a negative balance', () => {
    const s = summarizePaymentAmount(obligation, '121');
    assert.equal(s.problem, 'exceeds');
    assert.equal(s.after, '-1.0000');
});

test('50 against 120 shows 70 remaining and is valid', () => {
    const s = summarizePaymentAmount(obligation, '50');
    assert.equal(s.problem, null);
    assert.equal(s.after, '70.0000');
    assert.equal(s.available, '120.0000');
    assert.equal(s.planned, '120.0000');
    assert.equal(s.paid, '0.0000');
});

test('exactly 120 shows 0 remaining and is valid', () => {
    const s = summarizePaymentAmount(obligation, '120');
    assert.equal(s.problem, null);
    assert.equal(s.after, '0.0000');
});

test('zero, negative, empty and invalid amounts are rejected', () => {
    assert.equal(summarizePaymentAmount(obligation, '0').problem, 'notPositive');
    assert.equal(summarizePaymentAmount(obligation, '0.0000').problem, 'notPositive');
    assert.equal(summarizePaymentAmount(obligation, '-5').problem, 'notPositive');
    assert.equal(summarizePaymentAmount(obligation, '').problem, 'required');
    assert.equal(summarizePaymentAmount(obligation, 'abc').problem, 'invalid');
    assert.equal(summarizePaymentAmount(obligation, '1.00001').problem, 'invalid');
});

test('uses exact decimals: 0.1 + 0.2 style drift cannot happen', () => {
    const o = { planned_amount: '0.3000', paid_amount: '0.1000', remaining_amount: '0.2000', committed_amount: '0.0000' };
    assert.equal(summarizePaymentAmount(o, '0.2').after, '0.0000');
    assert.equal(summarizePaymentAmount(o, '0.2001').problem, 'exceeds');
});

test('partially paid obligation: remaining reflects previous payments', () => {
    const o = { planned_amount: '120.0000', paid_amount: '50.0000', remaining_amount: '70.0000', committed_amount: '0.0000' };
    assert.equal(summarizePaymentAmount(o, '70').after, '0.0000');
    assert.equal(summarizePaymentAmount(o, '70.0001').problem, 'exceeds');
});

test('committed cheque/draft amounts are excluded from the available balance, as the backend does', () => {
    const o = { planned_amount: '120.0000', paid_amount: '0.0000', remaining_amount: '120.0000', committed_amount: '100.0000' };
    const s = summarizePaymentAmount(o, '30');
    assert.equal(s.available, '20.0000');
    assert.equal(s.problem, 'exceeds');
    assert.equal(summarizePaymentAmount(o, '20').problem, null);
});
