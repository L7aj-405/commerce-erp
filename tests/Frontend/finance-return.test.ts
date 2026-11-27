// Run: node --test tests/Frontend/finance-return.test.ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { safeFinanceReturn, withFinanceReturn } from '../../resources/js/utils/financeReturn.ts';

test('only same-site Finance clients paths are honoured as a return target', () => {
    assert.equal(safeFinanceReturn('/finance/clients/comptes/12?section=invoices'), '/finance/clients/comptes/12?section=invoices');
    for (const bad of ['https://evil.test/finance/clients/x', '//evil.test/finance/clients/', '/sales/orders/1', '/finance/clients/' + String.fromCharCode(92) + 'evil', '/finance/clients/a' + String.fromCharCode(10) + 'b', '', null, undefined]) {
        assert.equal(safeFinanceReturn(bad as string | null | undefined), null, String(bad));
    }
});

test('the return target keeps the originating tab and filters, and legacy URLs map to their Finance clients alias', () => {
    assert.equal(withFinanceReturn('/invoices/5', '/finance/clients/comptes/12?section=invoices'), '/invoices/5?fc_return=%2Ffinance%2Fclients%2Fcomptes%2F12%3Fsection%3Dinvoices');
    assert.equal(withFinanceReturn('/sales/orders/9?x=1', '/finance/encaissements?month=2026-11&section=refunds'), '/sales/orders/9?x=1&fc_return=%2Ffinance%2Fclients%2Fencaissements%3Fmonth%3D2026-11%26section%3Drefunds');
    assert.equal(safeFinanceReturn(decodeURIComponent(new URL(withFinanceReturn('/payments/1', '/finance/creances?section=aging'), 'http://x').searchParams.get('fc_return') ?? '')), '/finance/clients/creances?section=aging');
});
