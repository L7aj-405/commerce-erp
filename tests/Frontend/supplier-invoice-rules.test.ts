// Run: node --test tests/Frontend/supplier-invoice-rules.test.ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { decimalDiff, decimalEquals, formatScaled, interpolate, parseScaled, sumDecimals } from '../../resources/js/components/finance/supplier-invoices/math.ts';
import { approvalProblems, canApprove, canVoid } from '../../resources/js/components/finance/supplier-invoices/rules.ts';

test('sums decimal strings exactly (no float drift)', () => {
    assert.equal(sumDecimals(['0.1', '0.2']).sum, '0.3000');
    assert.equal(sumDecimals(['1 234,50', '765.50']).sum, '2000.0000');
    assert.deepEqual(sumDecimals(['10', 'abc', '']), { sum: '10.0000', invalid: 1 });
});

test('exact equality and diff', () => {
    assert.ok(decimalEquals('120.00', '120'));
    assert.ok(!decimalEquals('120.01', '120.00'));
    assert.ok(!decimalEquals('', '0'));
    assert.equal(decimalDiff('100', '99.99'), '0.0100');
    assert.equal(decimalDiff('99.99', '100'), '-0.0100');
});

test('rejects over-precise input, accepts trailing zeros', () => {
    assert.equal(parseScaled('1.00001'), null);
    assert.equal(parseScaled('1.50000'), 15000n);
    assert.equal(formatScaled(-5n), '-0.0005');
});

test('interpolate replaces placeholders', () => {
    assert.equal(interpolate('Ligne {n} / {n}', { n: 3 }), 'Ligne 3 / 3');
});

const base = { controlTotal: '120.00', totalTtc: '120.0000', matchStatus: 'po_matched' as const, acknowledgeUnmatched: false, matchNote: '', warningCount: 0, acknowledgeWarnings: false };

test('approval: matched PO, exact control total is ready', () => {
    assert.ok(canApprove(base));
});

test('approval: control total must match exactly', () => {
    assert.ok(approvalProblems({ ...base, controlTotal: '120.01' }).controlTotal);
    assert.ok(approvalProblems({ ...base, controlTotal: '' }).controlTotal);
});

test('approval: unmatched needs acknowledgement and a note', () => {
    const v = { ...base, matchStatus: 'variance' as const };
    assert.ok(!canApprove(v));
    assert.ok(!canApprove({ ...v, acknowledgeUnmatched: true }));
    assert.ok(canApprove({ ...v, acknowledgeUnmatched: true, matchNote: 'Accord téléphonique' }));
});

test('approval: warnings must be acknowledged', () => {
    assert.ok(!canApprove({ ...base, warningCount: 2 }));
    assert.ok(canApprove({ ...base, warningCount: 2, acknowledgeWarnings: true }));
});

test('void: reason required, text required only for other', () => {
    assert.ok(!canVoid('', ''));
    assert.ok(canVoid('duplicate_entry', ''));
    assert.ok(!canVoid('other', '  '));
    assert.ok(canVoid('other', 'Facture reçue par erreur'));
});
