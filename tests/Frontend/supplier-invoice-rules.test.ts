// Run: node --test tests/Frontend/supplier-invoice-rules.test.ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { decimalDiff, decimalEquals, formatScaled, interpolate, parseScaled, sumDecimals } from '../../resources/js/components/finance/supplier-invoices/math.ts';
import { approvalProblems, canApprove, canVoid, unmappedErrors } from '../../resources/js/components/finance/supplier-invoices/rules.ts';

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

const base = { controlTotal: '120.00', totalTtc: '120.0000', matchStatus: 'po_matched' as const, acknowledgeUnmatched: false, matchNote: '', warningCodes: [] as string[], acknowledgedWarnings: [] as string[] };

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
    const w = { ...base, warningCodes: ['duplicate_number', 'same_total'] };
    assert.ok(!canApprove(w));
    assert.ok(!canApprove({ ...w, acknowledgedWarnings: ['duplicate_number'] }));
    assert.ok(canApprove({ ...w, acknowledgedWarnings: ['same_total', 'duplicate_number'] }));
});

test('void: reason required, text required only for other', () => {
    assert.ok(!canVoid('', ''));
    assert.ok(canVoid('duplicate_entry', ''));
    assert.ok(!canVoid('other', '  '));
    assert.ok(canVoid('other', 'Facture reçue par erreur'));
});

import { calculateLine, calculateTotals } from '../../resources/js/components/finance/supplier-invoices/math.ts';

test('line calculation mirrors the server: one half-up rounding from the exact product', () => {
    assert.deepEqual(calculateLine('3', '33.335', '20'), { line_ht: '100.0100', line_tva: '20.0000', line_ttc: '120.0100' }); // 100.005 -> 100.01 ; 20.002 -> 20.00
    assert.deepEqual(calculateLine('1', '0.005', '0'), { line_ht: '0.0100', line_tva: '0.0000', line_ttc: '0.0100' });
    assert.equal(calculateLine('0', '10', '20'), null);
    assert.equal(calculateLine('1', '', '20'), null);
});

test('totals sum rounded lines and add the bounded adjustment', () => {
    const lines = [calculateLine('2', '10', '20'), calculateLine('1', '0.99', '10')];
    assert.deepEqual(calculateTotals(lines, '-0.01'), { subtotal_ht: '20.9900', total_tva: '4.1000', rounding_adjustment: '-0.0100', total_ttc: '25.0800', incomplete: false });
    assert.equal(calculateTotals([null], '').incomplete, true);
});

test('server refusals not tied to a field are surfaced, never dropped (void refused after a payment / closed period)', () => {
    const voidFields = ['reason_code', 'reason'];
    const refusal = 'Un paiement est enregistré sur l’échéance OBL-1 : inversez d’abord les paiements, puis annulez la facture.';
    assert.deepEqual(unmappedErrors({ invoice: refusal }, voidFields), [refusal]);
    assert.deepEqual(unmappedErrors({ period: 'La période est clôturée.', invoice: refusal }, voidFields), ['La période est clôturée.', refusal]);
    // field errors stay on their field and are not duplicated in the banner
    assert.deepEqual(unmappedErrors({ reason: 'Précisez le motif.' }, voidFields), []);
    // blank / missing / duplicate messages produce nothing
    assert.deepEqual(unmappedErrors({ invoice: '  ' }, voidFields), []);
    assert.deepEqual(unmappedErrors(undefined, voidFields), []);
    assert.deepEqual(unmappedErrors({ a: 'x', b: 'x' }, voidFields), ['x']);
});
