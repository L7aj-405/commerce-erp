// Run: npm run test:frontend  (this file alone: node --experimental-strip-types --test tests/Frontend/navigation-registry.test.ts)
//
// Guards the single permission-aware navigation registry (resources/js/layouts/navigation.tsx):
// the seven-group architecture, deep-link preservation, permission / store / feature-flag / Test Mode
// filtering and FR/AR label coverage. Pure data checks: no browser, no request, no database.
import assert from 'node:assert/strict';
import path from 'node:path';
import { after, before, test } from 'node:test';
import { createServer, type ViteDevServer } from 'vite';

/* eslint-disable @typescript-eslint/no-explicit-any */
type Item = { href: string; label: string };
type Entry = Item & { key?: string; items?: Item[] };
type Group = { key: string; label: string; items: Entry[] };

const root = path.resolve(import.meta.dirname, '../..');
let server: ViteDevServer;
let nav: any;
let i18n: { fr: Record<string, string>; ar: Record<string, string> };

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
    nav = await server.ssrLoadModule('/resources/js/layouts/navigation.tsx');
    i18n = (await server.ssrLoadModule('/resources/js/i18n/translations.ts')) as any;
});

after(async () => {
    await server?.close();
});

const GROUP_ORDER = ['dashboard', 'sales', 'purchasing', 'finance', 'inventory', 'catalog', 'administration'];

/** Every destination of the previous navigation: none may disappear (deep links, authorization unchanged). */
const LEGACY_HREFS = [
    '/platform', '/work', '/pos', '/sales/orders', '/quotations', '/invoices', '/delivery-notes', '/sales/returns', '/my-commissions',
    '/catalog/products', '/catalog/categories', '/catalog/brands', '/catalog/products/import', '/catalog/purchase-prices', '/catalog/non-stock-items',
    '/inventory/stock', '/inventory/movements', '/inventory/transfers', '/inventory/transfer-requests', '/inventory/warehouses',
    '/procurement', '/procurement/purchase-orders', '/procurement/supplier-requests', '/procurement/out-of-stock-articles', '/procurement/suppliers',
    '/finance', '/finance/ventes', '/finance/ca-encaisse', '/finance/creances', '/finance/situation-fin-de-mois',
    '/finance/commissions', '/finance/commissions/ledger', '/finance/commissions/rules', '/finance/facturation', '/finance/factures-fournisseurs',
    '/finance/encaissements', '/payments', '/financial-accounts', '/finance/journal',
    '/sales/customers', '/contacts', '/integrations/woocommerce', '/integrations/woocommerce/stock-tasks',
    '/activity', '/system-health', '/organizations/9/users-access', '/document-profile', '/document-profile/studio', '/settings/stores',
    '/quotation-settings', '/document-stamp', '/return-policy', '/email-settings', '/organization-backups', '/settings/data-reset',
    '/settings/ai-mcp', '/account/notifications', '/catalog/tax-rates',
];

const tenant = (permissions: string[], opts: { store?: boolean; organization?: boolean } = {}) =>
    ({ permissions, store: opts.store === false ? null : { id: 1 }, organization: opts.organization === false ? null : { id: 9 } }) as any;

// Superset of every permission string used by the registry, derived from the registry itself.
const everyPermission = (groups: Group[]) => {
    const found = new Set<string>();
    const walk = (value: any) => {
        if (Array.isArray(value)) value.forEach(walk);
        else if (value && typeof value === 'object') {
            if (value.permission) [value.permission].flat().forEach((p: string) => found.add(p));
            if (value.items) walk(value.items);
        }
    };
    walk(groups);
    return [...found];
};

const tFor = (locale: 'fr' | 'ar') => (key: string) => i18n[locale][key] ?? `MISSING:${key}`;
const flat = (groups: Group[]): Item[] => groups.flatMap((g) => g.items.flatMap((e) => (e.items ? e.items : [e])));
const build = (locale: 'fr' | 'ar', t: any, sandbox = false, supplierInvoices = true) => nav.buildNavGroups(tFor(locale), t, sandbox, supplierInvoices) as Group[];
const full = (locale: 'fr' | 'ar' = 'fr', sandbox = false, supplierInvoices = true) => {
    const permissions = everyPermission(build(locale, tenant([]), sandbox, supplierInvoices));
    const t = tenant(permissions);
    return nav.visibleNavGroups(build(locale, t, sandbox, supplierInvoices), t) as Group[];
};

test('exposes exactly the seven target groups in order', () => {
    assert.deepEqual(full().map((g) => g.key), GROUP_ORDER);
    assert.deepEqual(full().map((g) => g.label), [
        'Vue d’ensemble', 'Ventes & Clients', 'Achats & Fournisseurs', 'Finance & Trésorerie', 'Stock & Entrepôts', 'Catalogue', 'Administration',
    ]);
});

test('keeps every legacy destination reachable and lists none twice', () => {
    const hrefs = flat(full()).map((i) => i.href);
    for (const href of LEGACY_HREFS) assert.ok(hrefs.includes(href), `legacy destination missing from navigation: ${href}`);
    assert.equal(new Set(hrefs).size, hrefs.length, 'a destination is listed twice');
});

test('hides entries the user may not open and never leaks group names', () => {
    const t = tenant(['products.view']);
    const groups = nav.visibleNavGroups(build('fr', t), t) as Group[];
    // Administration remains only for the two entries that carry no permission today (per-user notifications and the
    // member-visible AI/MCP page, as before this redesign); nothing permission-gated leaks.
    assert.deepEqual(groups.map((g) => g.key), ['dashboard', 'catalog', 'administration']);
    assert.deepEqual(flat(groups).map((i) => i.href), ['/platform', '/catalog/products', '/account/notifications', '/settings/ai-mcp']);

    const none = nav.visibleNavGroups(build('fr', tenant([])), tenant([])) as Group[];
    assert.deepEqual(none.map((g) => g.key), ['dashboard', 'administration']);
    assert.deepEqual(flat(none).map((i) => i.href), ['/platform', '/account/notifications', '/settings/ai-mcp']);
});

test('store-bound entries disappear without an active store', () => {
    const permissions = everyPermission(build('fr', tenant([])));
    const t = tenant(permissions, { store: false });
    const hrefs = flat(nav.visibleNavGroups(build('fr', t), t)).map((i) => i.href);
    for (const href of ['/pos', '/sales/orders', '/quotations', '/invoices', '/delivery-notes', '/sales/returns', '/procurement/supplier-requests', '/payments']) {
        assert.ok(!hrefs.includes(href), `${href} must need an active store`);
    }
    assert.ok(hrefs.includes('/finance/encaissements'), 'organization-wide Finance reporting stays reachable');
});

test('supplier invoices stay behind the kill switch and Test Mode', () => {
    const has = (groups: Group[]) => flat(groups).some((i) => i.href.startsWith('/finance/factures-fournisseurs'));
    assert.equal(has(full('fr', false, true)), true);
    assert.equal(has(full('fr', false, false)), false, 'feature flag off');
    assert.equal(has(full('fr', true, true)), false, 'Test Mode');
});

test('supplier invoices live under Achats & Fournisseurs, not Finance, with the same route and permission', () => {
    const groups = full();
    const where = (href: string) => groups.find((g) => g.items.some((e) => e.href === href || e.items?.some((i) => i.href === href)))?.key;
    assert.equal(where('/finance/factures-fournisseurs'), 'purchasing');
    const all = build('fr', tenant([])).flatMap((g) => g.items.flatMap((e: any) => e.items ?? [e])) as any[];
    assert.equal(all.find((i) => i.href === '/finance/factures-fournisseurs').permission, 'supplier_invoices.view');
    // Purchase orders, requirements and suppliers stay clearly reachable.
    for (const href of ['/procurement/purchase-orders', '/procurement', '/procurement/suppliers']) assert.equal(where(href), 'purchasing');
});

test('Finance keeps incoming customer money and outgoing supplier money apart', () => {
    const finance = full().find((g) => g.key === 'finance')!;
    const section = (key: string) => finance.items.find((e: any) => e.key === key) as any;
    assert.deepEqual(section('finance-incoming').items.map((i: Item) => i.href), ['/finance/encaissements', '/payments', '/finance/creances']);
    // /payments is the customer payments list and must never be filed with supplier payments / obligations.
    for (const key of ['finance-obligations', 'finance-reports']) {
        assert.ok(!section(key).items.some((i: Item) => i.href === '/payments' || i.href === '/finance/encaissements'), key);
    }
    // The single existing outgoing-payment entry point is named for what it is (no dedicated list exists).
    const monthEnd = finance.items.find((e) => e.href === '/finance/situation-fin-de-mois')!;
    assert.match(monthEnd.label, /fournisseurs/i);
    assert.match(monthEnd.label, /fin de mois/i);
    const customerPayments = section('finance-incoming').items.find((i: Item) => i.href === '/payments');
    assert.match(customerPayments.label, /clients/i);
    // /finance/journal is customer-side (invoices, credit notes, customer payments, refunds): named accordingly.
    const journal = section('finance-reports').items.find((i: Item) => i.href === '/finance/journal');
    assert.equal(journal.label, 'Journal des ventes');
    assert.deepEqual(finance.items.map((e) => e.href ?? e.key), [
        '/finance', 'finance-incoming', '/finance/situation-fin-de-mois', 'finance-obligations', '/financial-accounts', 'finance-reports', 'finance-commissions',
    ]);
});

test('moved entries keep their permission', () => {
    const items = flat(full());
    const permissionOf = (href: string) => (build('fr', tenant([])).flatMap((g) => g.items.flatMap((e: any) => e.items ?? [e])) as any[]).find((i) => i.href === href)?.permission;
    assert.ok(items.some((i) => i.href === '/catalog/tax-rates'));
    assert.equal(permissionOf('/catalog/tax-rates'), 'tax_rates.view');
    assert.equal(permissionOf('/catalog/units'), 'units.view');
    assert.equal(permissionOf('/sales/customers'), 'customers.view');
});

test('deep links highlight the right group (longest prefix wins)', () => {
    const groups = full();
    const m = (p: string) => nav.matchActiveNav(groups, p);
    assert.equal(m('/sales/orders/42')?.groupKey, 'sales');
    assert.equal(m('/sales/customers/7/edit')?.groupKey, 'sales');
    assert.equal(m('/procurement/purchase-orders/5')?.item.href, '/procurement/purchase-orders');
    assert.equal(m('/procurement/suppliers/3')?.groupKey, 'purchasing');
    assert.equal(m('/catalog/tax-rates')?.groupKey, 'catalog');
    assert.equal(m('/integrations/woocommerce/stock-tasks')?.groupKey, 'administration');
    assert.equal(m('/finance/commissions/ledger/12')?.groupKey, 'finance');
});

test('every label resolves in French and Arabic (no raw key, Arabic differs from French)', () => {
    for (const locale of ['fr', 'ar'] as const) {
        const groups = build(locale, tenant(everyPermission(build('fr', tenant([])))));
        const labels = groups.flatMap((g) => [g.label, ...g.items.flatMap((e: any) => [e.label, ...(e.items ?? []).map((i: Item) => i.label)])]);
        for (const label of labels) {
            assert.ok(label && !label.startsWith('MISSING:'), `${locale}: unresolved label ${label}`);
        }
    }
    for (const key of Object.keys(i18n.fr).filter((k) => k.startsWith('nav.group.') || k.startsWith('nav.section.'))) {
        assert.ok(i18n.ar[key], `Arabic translation missing for ${key}`);
        assert.notEqual(i18n.ar[key], i18n.fr[key], `${key} is not translated to Arabic`);
    }
});

test('breadcrumbs follow the registry, add a terminal crumb on deeper URLs and stay empty off-registry', () => {
    const groups = full();
    const t = tFor('fr');
    const crumbs = (p: string) => nav.buildBreadcrumbs(groups, p, t) as { label: string; href?: string }[];
    assert.deepEqual(crumbs('/platform'), []);
    assert.deepEqual(crumbs('/unknown/page'), []);
    assert.deepEqual(crumbs('/procurement/purchase-orders'), [
        { label: 'Achats & Fournisseurs', href: '/procurement/purchase-orders' },
        { label: 'Commandes fournisseur' },
    ]);
    const detail = crumbs('/procurement/purchase-orders/5');
    assert.deepEqual(detail.map((c) => c.label), ['Achats & Fournisseurs', 'Commandes fournisseur', 'Détail']);
    assert.equal(detail[1]!.href, '/procurement/purchase-orders');
    assert.equal(detail[2]!.href, undefined);
    assert.deepEqual(crumbs('/finance/factures-fournisseurs/nouvelle').map((c) => c.label), ['Achats & Fournisseurs', 'Factures fournisseurs', 'Création']);
    assert.deepEqual(crumbs('/sales/customers/7/edit').map((c) => c.label), ['Ventes & Clients', 'Clients & contacts', 'Clients', 'Modification']);
    assert.deepEqual(crumbs('/finance/encaissements').map((c) => c.label), ['Finance & Trésorerie', 'Encaissements clients', 'Encaissements du mois']);
});

test('breadcrumbs never reveal a page the user may not open', () => {
    const t = tenant(['products.view']);
    const groups = nav.visibleNavGroups(build('fr', t), t) as Group[];
    assert.deepEqual(nav.buildBreadcrumbs(groups, '/finance/encaissements', tFor('fr')), []);
});

test('quick actions point at genuine creation workflows and respect permission, flag and Test Mode', () => {
    const t = (permissions: string[]) => tenant(permissions);
    const all = ['pos.access', 'supplier_invoices.manage', 'products.create', 'contacts.create', 'procurement.manage'];
    const hrefs = (permissions: string[], options?: object) => (nav.buildQuickActions(tFor('fr'), t(permissions), options) as Item[]).map((a) => a.href);
    const on = { supplierInvoicesEnabled: true, sandboxActive: false };
    assert.deepEqual(hrefs(all, on), ['/pos', '/finance/factures-fournisseurs/nouvelle', '/catalog/products/create', '/contacts/create']);
    // "New purchase" must not point at the procurement list any more, and no standalone PO creation is invented.
    assert.ok(!hrefs(all, on).includes('/procurement'));
    assert.ok(!hrefs(all, { ...on, supplierInvoicesEnabled: false }).includes('/finance/factures-fournisseurs/nouvelle'), 'flag off');
    assert.ok(!hrefs(all, { ...on, sandboxActive: true }).includes('/finance/factures-fournisseurs/nouvelle'), 'Test Mode');
    assert.ok(!hrefs(['pos.access'], on).includes('/finance/factures-fournisseurs/nouvelle'), 'permission');
    assert.deepEqual(hrefs([], on), []);
});
