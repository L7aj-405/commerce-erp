// Run: node --test tests/Frontend/supplier-fiscal.test.ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { blankFiscalForm, fiscalPayload, fiscalToForm, mapFiscalWarnings, mapSupplierFiscal } from '../../resources/js/components/procurement/supplierFiscal.ts';

test('payload: blanks clear to null, IBAN omitted unless typed', () => {
    const p = fiscalPayload({ ...blankFiscalForm, ice: ' 001234567000089 ', default_currency_code: 'mad', default_payment_term_days: '30' });
    assert.equal(p.ice, '001234567000089');
    assert.equal(p.rc_number, null);
    assert.equal(p.default_payment_term_days, 30);
    assert.equal(p.default_currency_code, 'MAD');
    assert.ok(!('bank_iban' in p));
    assert.equal(fiscalPayload({ ...blankFiscalForm, bank_iban: 'MA64 0117 8000' }).bank_iban, 'MA640117' + '8000');
});

test('form prefill never contains an IBAN', () => {
    assert.equal(fiscalToForm({ ice: 'X', bank_iban: 'MA64FULL', bank_iban_masked: '****1234' }).bank_iban, '');
});

test('warnings: maps backend {field,code,message} items to messages, drops blanks', () => {
    assert.deepEqual(mapFiscalWarnings([{ field: 'ice', code: 'ice_format', message: 'ICE invalide' }, { message: ' ' }]), ['ICE invalide']);
    assert.deepEqual(mapFiscalWarnings(undefined), []);
});

test('has_bank_iban comes from the list flag; masked implies presence', () => {
    assert.equal(mapSupplierFiscal({ has_bank_iban: true }).has_bank_iban, true);
    assert.equal(mapSupplierFiscal({ bank_iban_masked: '****1234' }).has_bank_iban, true);
    assert.equal(mapSupplierFiscal({}).has_bank_iban, false);
});
