// Run: node --experimental-strip-types --test tests/Frontend/reason-validation.test.ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isReasonBlank } from '../../resources/js/components/finance/reasonValidation.ts';

test('empty, null and undefined reasons are blank', () => {
    assert.equal(isReasonBlank(''), true);
    assert.equal(isReasonBlank(null), true);
    assert.equal(isReasonBlank(undefined), true);
});

test('whitespace-only reasons (spaces, tabs, newlines, nbsp) are blank', () => {
    assert.equal(isReasonBlank('   '), true);
    assert.equal(isReasonBlank('\n\t '), true);
    assert.equal(isReasonBlank('\u00a0'), true);
});

test('a real reason is accepted, including surrounding whitespace', () => {
    assert.equal(isReasonBlank('Paiement rejeté'), false);
    assert.equal(isReasonBlank('  x  '), false);
});
