// Run: node --test tests/Frontend/currency-format.test.ts
import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { formatMoney } from '../../resources/js/utils/format.ts';

const setLang = (lang: string) => {
    (globalThis as { document?: unknown }).document = { documentElement: { lang } };
};

afterEach(() => {
    delete (globalThis as { document?: unknown }).document;
});

// Summary cards and the customer timeline both call formatMoney, so they share one unit label.
test('French renders MAD as DH', () => {
    setLang('fr');
    assert.match(formatMoney('1428', 'MAD'), /^1\s428,00 DH$/u);
    assert.doesNotMatch(formatMoney('1428', 'MAD'), /د\.م/u);
});

test('Arabic renders MAD as DH, never د.م.', () => {
    setLang('ar');
    const out = formatMoney('1428', 'MAD');
    assert.match(out, /DH$/u);
    assert.doesNotMatch(out, /د\.م/u);
});

test('same amount gives the same unit in both locales; precision unchanged', () => {
    setLang('fr');
    const fr = formatMoney('19668.5', 'MAD');
    setLang('ar');
    const ar = formatMoney('19668.5', 'MAD');
    assert.ok(fr.endsWith(' DH') && ar.endsWith(' DH'));
    assert.match(fr, /,50 DH$/u);
});

test('non-MAD currencies keep their ISO code', () => {
    setLang('ar');
    assert.ok(formatMoney('10', 'EUR').endsWith(' EUR'));
});
