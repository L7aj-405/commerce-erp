// Run: node --test tests/Frontend/supplier-invoice-error-feedback.test.ts
//
// Server-renders VoidDialog / ApproveDialog with the exact error bag Inertia hands to `onError`
// when the backend refuses (`errors.invoice`, see tests/Feature/Finance/SupplierInvoiceVoidTest.php).
// This is a render test, NOT a browser test and NOT proof of a real server rejection:
// no request is made and no invoice is touched.
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { after, before, test } from 'node:test';
import { pathToFileURL } from 'node:url';
import { rolldown } from 'rolldown';

type Renderer = { renderVoid: (e: Record<string, string>) => string; renderApprove: (e: Record<string, string>, m?: string) => string };

const root = path.resolve(import.meta.dirname, '../..');
const outDir = mkdtempSync(path.join(tmpdir(), 'si-error-feedback-'));
let ui: Renderer;

const setLocale = (locale: 'fr' | 'ar') => {
    (globalThis as { window?: unknown }).window = { localStorage: { getItem: () => locale, setItem: () => {} } };
};

// Server message as the backend would send it (French, accounting wording) — must appear verbatim.
const VOID_REASON = 'Cette facture a des paiements actifs : annulez-les avant de l’annuler.';
const APPROVE_REASON = 'La période comptable est clôturée : approbation impossible.';

before(async () => {
    const bundle = await rolldown({
        input: path.join(root, 'tests/Frontend/support/render-dialogs.tsx'),
        platform: 'node',
        resolve: { alias: { '@': path.join(root, 'resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
        logLevel: 'silent',
    });
    const file = path.join(outDir, 'render-dialogs.mjs');
    await bundle.write({ file, format: 'esm' });
    ui = (await import(pathToFileURL(file).href)) as Renderer;
});

after(() => {
    delete (globalThis as { window?: unknown }).window;
    rmSync(outDir, { recursive: true, force: true });
});

// True when a <button> with this exact label exists and carries no `disabled` attribute (Tailwind `disabled:` classes are not the attribute).
const buttonEnabled = (html: string, label: string) =>
    [...html.matchAll(/<button(\s[^>]*)>([^<]*)<\/button>/gu)].some(([, attrs, text]) => text.trim() === label && !/\sdisabled(=|\s|$)/u.test(` ${attrs}`));

const alertBlock = (html: string) => html.match(/<[^>]*role="alert"[\s\S]*?<\/div>\s*<\/div>/u)?.[0] ?? '';

test('void refusal (fr): title, exact server reason, unchanged hint, dialog still usable', () => {
    setLocale('fr');
    const html = ui.renderVoid({ invoice: VOID_REASON });
    assert.ok(html.includes('Annulation refusée'));
    assert.ok(html.includes(VOID_REASON), 'server reason must not be discarded');
    assert.ok(html.includes('La facture reste approuvée : rien n&#x27;a été modifié.'));
    assert.match(html, /role="alertdialog"/u);
    assert.match(html, /dir="ltr"/u);
    // still usable: cancel is enabled and the confirm button is present and enabled
    assert.ok(buttonEnabled(html, 'Annuler'));
    assert.ok(buttonEnabled(html, 'Annuler la facture'));
    assert.match(html, /<select/u);
    assert.match(html, /<textarea/u);
    assert.ok(alertBlock(html).includes(VOID_REASON));
});

test('void refusal (ar): Arabic title + hint, RTL, server reason kept verbatim', () => {
    setLocale('ar');
    const html = ui.renderVoid({ invoice: VOID_REASON });
    assert.ok(html.includes('تم رفض الإلغاء'));
    assert.ok(html.includes('تبقى الفاتورة معتمدة: لم يتم تعديل أي شيء.'));
    assert.ok(html.includes(VOID_REASON));
    assert.match(html, /dir="rtl"/u);
    assert.doesNotMatch(html, /Annulation refusée/u);
});

test('approve refusal (fr): title, exact server reason, unchanged hint, form still present', () => {
    setLocale('fr');
    const html = ui.renderApprove({ invoice: APPROVE_REASON });
    assert.ok(html.includes('Approbation refusée'));
    assert.ok(html.includes(APPROVE_REASON));
    assert.ok(html.includes('La facture reste en brouillon : rien n&#x27;a été modifié.'));
    assert.match(html, /dir="ltr"/u);
    assert.match(html, /<input/u);
    assert.ok(buttonEnabled(html, 'Annuler'));
    assert.ok(buttonEnabled(html, 'Approuver la facture'));
});

test('approve refusal (ar): Arabic title + hint, RTL, server reason kept verbatim', () => {
    setLocale('ar');
    const html = ui.renderApprove({ invoice: APPROVE_REASON });
    assert.ok(html.includes('تم رفض الاعتماد'));
    assert.ok(html.includes('تبقى الفاتورة مسودة: لم يتم تعديل أي شيء.'));
    assert.ok(html.includes(APPROVE_REASON));
    assert.match(html, /dir="rtl"/u);
});

test('no refusal block when there is no unmapped error, or only field errors', () => {
    setLocale('fr');
    assert.doesNotMatch(ui.renderVoid({}), /Annulation refusée/u);
    assert.doesNotMatch(ui.renderApprove({}), /Approbation refusée/u);
    const withField = ui.renderVoid({ reason_code: 'Motif requis.' });
    assert.doesNotMatch(withField, /Annulation refusée/u);
    assert.ok(withField.includes('Motif requis.'), 'field errors still render inline');
    const approveField = ui.renderApprove({ control_total_ttc: 'Total invalide.' });
    assert.doesNotMatch(approveField, /Approbation refusée/u);
    assert.ok(approveField.includes('Total invalide.'));
});

test('multiple distinct server reasons are all shown; duplicates collapse', () => {
    setLocale('fr');
    const html = ui.renderVoid({ invoice: VOID_REASON, other: 'Autre raison.', again: VOID_REASON });
    assert.ok(html.includes('Autre raison.'));
    assert.equal(html.split(VOID_REASON).length - 1, 1);
});
