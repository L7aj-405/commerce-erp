// Server-renders the receipt refusal block with the error bag Inertia hands to `onError`.
// A render test, NOT a browser test: no request is made.
import assert from 'node:assert/strict';
import path from 'node:path';
import { after, before, test } from 'node:test';
import { createServer, type ViteDevServer } from 'vite';

type Renderer = { renderReceiptRefusal: (errors: Record<string, string>) => string };

const root = path.resolve(import.meta.dirname, '../..');
let server: ViteDevServer;
let ui: Renderer;

const setLocale = (locale: 'fr' | 'ar') => {
    (globalThis as { window?: unknown }).window = { localStorage: { getItem: () => locale, setItem: () => {} } };
};

before(async () => {
    server = await createServer({
        root,
        configFile: false,
        appType: 'custom',
        logLevel: 'silent',
        server: { middlewareMode: true, hmr: false, watch: null },
        optimizeDeps: { noDiscovery: true },
        resolve: { alias: { '@': path.join(root, 'resources/js') } },
    });
    ui = (await server.ssrLoadModule('/tests/Frontend/support/render-receipt-refusal.tsx')) as Renderer;
});

after(async () => {
    delete (globalThis as { window?: unknown }).window;
    await server?.close();
});

for (const key of ['reservation', 'quantity', 'allocation', 'order', 'status', 'something_new']) {
    test(`a refusal under the "${key}" key is announced with its reason`, () => {
        setLocale('fr');
        const html = ui.renderReceiptRefusal({ [key]: 'Motif du serveur.' });
        assert.match(html, /role="alert"/u);
        assert.ok(html.includes('Réception refusée'));
        assert.ok(html.includes('Motif du serveur.'));
    });
}

test('Arabic heading, server reason kept verbatim, duplicates collapsed', () => {
    setLocale('ar');
    const html = ui.renderReceiptRefusal({ a: 'Motif du serveur.', b: 'Motif du serveur.' });
    assert.ok(html.includes('تم رفض الاستلام'));
    assert.equal(html.split('Motif du serveur.').length - 1, 1);
});

test('nothing is rendered without errors', () => {
    assert.equal(ui.renderReceiptRefusal({}), '');
});
