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

const GROUP_ORDER = ['dashboard', 'sales', 'contacts', 'purchasing', 'finance', 'inventory', 'catalog', 'administration'];

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
    '/quotation-settings', '/customer-credit-settings', '/document-stamp', '/return-policy', '/email-settings', '/organization-backups', '/settings/data-reset',
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
            if (value.alsoRequires) found.add(value.alsoRequires);
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

test('exposes exactly the eight target groups in order (Contacts is a standalone module)', () => {
    assert.deepEqual(full().map((g) => g.key), GROUP_ORDER);
    assert.deepEqual(full().map((g) => g.label), [
        'Vue d’ensemble', 'Ventes', 'Contacts', 'Achats & Fournisseurs', 'Finance & Trésorerie', 'Stock & Entrepôts', 'Catalogue', 'Administration',
    ]);
});

test('keeps every legacy destination reachable and lists none twice', () => {
    const groups = full();
    const hrefs = flat(groups).map((i) => i.href);
    for (const href of LEGACY_HREFS) {
        // Reachable = listed in the navigation, or an explicit alias of a listed entry (Finance clients keeps legacy URLs alive).
        const alias = (nav.matchActiveNav(groups, href)?.item.aliases ?? []).includes(href);
        assert.ok(hrefs.includes(href) || alias, `legacy destination missing from navigation: ${href}`);
    }
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
    assert.ok(hrefs.includes('/finance/clients/encaissements'), 'organization-wide Finance reporting stays reachable');
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
    for (const href of ['/procurement/purchase-orders', '/procurement']) assert.equal(where(href), 'purchasing');
    // The supplier directory moved to Contacts; its URL stays an alias of the Fournisseurs view.
    assert.equal(nav.matchActiveNav(groups, '/procurement/suppliers/3')?.groupKey, 'contacts');
});

const FINANCE_CLIENTS = [
    ['/finance/clients', 'Vue financière clients'],
    ['/finance/clients/comptes', 'Comptes clients'],
    ['/finance/clients/facturation', 'Factures & avoirs'],
    ['/finance/clients/encaissements', 'Encaissements & instruments'],
    ['/finance/clients/creances', 'Recouvrement & crédit'],
    ['/finance/clients/rapports', 'Rapports & journal'],
];

test('Finance & Trésorerie has the approved six areas and Finance clients lists exactly six entries in order', () => {
    const finance = full().find((g) => g.key === 'finance')!;
    assert.deepEqual(finance.items.map((e) => e.href ?? e.key), [
        '/finance', 'finance-clients', 'finance-suppliers', 'finance-staff', 'finance-treasury', 'finance-steering',
    ]);
    assert.deepEqual(finance.items.map((e) => e.label), [
        'Vue d’ensemble', 'Finance clients', 'Finance fournisseurs', 'Finance du personnel', 'Trésorerie générale', 'Pilotage financier',
    ]);
    const clients = finance.items.find((e: any) => e.key === 'finance-clients') as any;
    // The tenth item (/payments) exists only for users WITHOUT finance.view; with it, exactly the six approved entries remain.
    assert.deepEqual(clients.items.map((i: Item) => [i.href, i.label]), FINANCE_CLIENTS);
});

test('other Finance areas keep their existing destinations and the supplier-invoices entry stays under Achats', () => {
    const finance = full().find((g) => g.key === 'finance')!;
    const hrefs = (key: string) => (finance.items.find((e: any) => e.key === key) as any).items.map((i: Item) => i.href);
    assert.deepEqual(hrefs('finance-suppliers'), ['/finance/situation-fin-de-mois/echeanciers-fournisseurs']);
    assert.deepEqual(hrefs('finance-staff'), ['/finance/commissions', '/finance/commissions/ledger', '/finance/commissions/rules', '/finance/personnel']);
    assert.deepEqual(hrefs('finance-treasury'), ['/financial-accounts']);
    assert.deepEqual(hrefs('finance-steering'), ['/finance/situation-fin-de-mois', '/finance/situation-fin-de-mois/charges-recurrentes']);
    // The single outgoing-payment entry point keeps its honest label.
    const monthEnd = (finance.items.find((e: any) => e.key === 'finance-steering') as any).items[0];
    assert.match(monthEnd.label, /fournisseurs/i);
    assert.match(monthEnd.label, /fin de mois/i);
    // Customer money never sits in a supplier / staff / treasury / steering area.
    for (const key of ['finance-suppliers', 'finance-staff', 'finance-treasury', 'finance-steering']) {
        assert.ok(!hrefs(key).some((h: string) => h.startsWith('/finance/clients') || h === '/payments' || h === '/finance/encaissements'), key);
    }
});

test('Finance clients entries keep the permissions of the pages they front, and Comptes clients needs both permissions', () => {
    const items = build('fr', tenant([])).find((g) => g.key === 'finance')!.items.find((e: any) => e.key === 'finance-clients') as any;
    for (const item of items.items.filter((i: any) => i.href.startsWith('/finance/clients'))) assert.equal(item.permission, 'finance.view', item.href);
    assert.equal(items.items.find((i: any) => i.href === '/finance/clients/comptes').alsoRequires, 'customers.view');
    const onlyFinance = tenant(['finance.view']);
    const hrefs = flat(nav.visibleNavGroups(build('fr', onlyFinance), onlyFinance)).map((i) => i.href);
    assert.ok(!hrefs.includes('/finance/clients/comptes'), 'finance.view alone must not expose customer accounts');
    assert.ok(hrefs.includes('/finance/clients/rapports'));
    const both = tenant(['finance.view', 'customers.view']);
    assert.ok(flat(nav.visibleNavGroups(build('fr', both), both)).some((i) => i.href === '/finance/clients/comptes'));
});

test('a payments-only user keeps their direct (store-bound) entry; Finance users reach /payments from Encaissements clients', () => {
    const hrefsFor = (permissions: string[], store = true) => {
        const t = tenant(permissions, { store });
        return flat(nav.visibleNavGroups(build('fr', t), t)).map((i) => i.href);
    };
    assert.ok(hrefsFor(['payments.view']).includes('/payments'));
    assert.ok(!hrefsFor(['payments.view'], false).includes('/payments'), 'needs an active store');
    assert.ok(!hrefsFor(['payments.view', 'finance.view']).includes('/payments'));
    const groups = full();
    assert.equal(nav.matchActiveNav(groups, '/payments/12')?.item.href, '/finance/clients/encaissements');
});

test('moved entries keep their permission', () => {
    const items = flat(full());
    const permissionOf = (href: string) => (build('fr', tenant([])).flatMap((g) => g.items.flatMap((e: any) => e.items ?? [e])) as any[]).find((i) => i.href === href)?.permission;
    assert.ok(items.some((i) => i.href === '/catalog/tax-rates'));
    assert.equal(permissionOf('/catalog/tax-rates'), 'tax_rates.view');
    assert.equal(permissionOf('/catalog/units'), 'units.view');
    // The customer directory is the Clients view of Contacts: still reachable with customers.view alone.
    assert.deepEqual(permissionOf('/contacts?role=client'), ['contacts.view', 'customers.view']);
    assert.deepEqual(permissionOf('/contacts?role=supplier'), ['contacts.view', 'suppliers.view']);
});

test('deep links highlight the right group (longest prefix wins)', () => {
    const groups = full();
    const m = (p: string) => nav.matchActiveNav(groups, p);
    assert.equal(m('/sales/orders/42')?.groupKey, 'sales');
    assert.equal(m('/sales/customers/7/edit')?.groupKey, 'contacts');
    assert.equal(m('/procurement/purchase-orders/5')?.item.href, '/procurement/purchase-orders');
    assert.equal(m('/procurement/suppliers/3')?.groupKey, 'contacts');
    assert.equal(m('/catalog/tax-rates')?.groupKey, 'catalog');
    assert.equal(m('/integrations/woocommerce/stock-tasks')?.groupKey, 'administration');
    assert.equal(m('/finance/commissions/ledger/12')?.groupKey, 'finance');
    // Legacy URLs highlight their Finance clients entry; deeper URLs keep the longest match.
    assert.equal(m('/finance/facturation')?.item.href, '/finance/clients/facturation');
    assert.equal(m('/finance/encaissements')?.item.href, '/finance/clients/encaissements');
    assert.equal(m('/finance/creances')?.item.href, '/finance/clients/creances');
    assert.equal(m('/finance/journal')?.item.href, '/finance/clients/rapports');
    assert.equal(m('/finance/ventes')?.item.href, '/finance/clients/rapports');
    assert.equal(m('/finance/ca-encaisse')?.item.href, '/finance/clients/rapports');
    assert.equal(m('/finance/clients/comptes/42')?.item.href, '/finance/clients/comptes');
    assert.equal(m('/finance/situation-fin-de-mois/echeanciers-fournisseurs/3')?.item.href, '/finance/situation-fin-de-mois/echeanciers-fournisseurs');
    assert.equal(m('/finance/situation-fin-de-mois')?.item.href, '/finance/situation-fin-de-mois');
    assert.equal(m('/financial-accounts/5/movements')?.item.href, '/financial-accounts');
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
    assert.deepEqual(crumbs('/sales/customers/7/edit').map((c) => c.label), ['Contacts', 'Clients', 'Modification']);
    assert.deepEqual(crumbs('/finance/encaissements').map((c) => c.label), ['Finance & Trésorerie', 'Finance clients', 'Encaissements & instruments']);
    assert.deepEqual(crumbs('/finance/clients/encaissements').map((c) => c.label), ['Finance & Trésorerie', 'Finance clients', 'Encaissements & instruments']);
    assert.deepEqual(crumbs('/payments/7').map((c) => c.label), ['Finance & Trésorerie', 'Finance clients', 'Encaissements & instruments', 'Détail']);
    assert.deepEqual(crumbs('/finance/clients/comptes/42').map((c) => c.label), ['Finance & Trésorerie', 'Finance clients', 'Comptes clients', 'Détail']);
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

// ---------------------------------------------------------------- Unified Contacts (Phase 2)

const CONTACT_VIEWS = [
    ['/contacts', 'Tous les contacts'],
    ['/contacts?role=client', 'Clients'],
    ['/contacts?role=supplier', 'Fournisseurs'],
    ['/contacts?role=internal', 'Contacts internes'],
    ['/contacts?scope=external', 'Contacts externes'],
    ['/contacts?group=partners', 'Partenaires & prestataires'],
    ['/contacts/settings', 'Paramètres'],
];

test('Contacts is a standalone module with the approved seven entries; Ventes no longer lists customers; Achats no longer lists suppliers', () => {
    const groups = full();
    const contacts = groups.find((g) => g.key === 'contacts')!;
    assert.deepEqual(contacts.items.map((i) => [i.href, i.label]), CONTACT_VIEWS);
    const sales = groups.find((g) => g.key === 'sales')!;
    assert.equal(sales.label, 'Ventes');
    assert.ok(!flat([sales]).some((i) => i.href === '/sales/customers' || i.href === '/contacts'), 'no customer or contact directory under Ventes');
    assert.ok(!flat([groups.find((g) => g.key === 'purchasing')!]).some((i) => i.href === '/procurement/suppliers'), 'no supplier directory under Achats');
    // The other sales and purchasing entries are untouched.
    for (const href of ['/pos', '/sales/orders', '/quotations', '/invoices', '/delivery-notes', '/sales/returns']) assert.ok(flat([sales]).some((i) => i.href === href), href);
});

test('a query-string entry highlights its own view; the plain directory and detail pages highlight "Tous les contacts"', () => {
    const groups = full();
    const at = (p: string, search = '') => nav.matchActiveNav(groups, p, search)?.item.href;
    assert.equal(at('/contacts'), '/contacts');
    assert.equal(at('/contacts', 'role=client'), '/contacts?role=client');
    assert.equal(at('/contacts', 'role=client&search=ac'), '/contacts?role=client');
    assert.equal(at('/contacts', 'role=supplier'), '/contacts?role=supplier');
    assert.equal(at('/contacts', 'role=internal'), '/contacts?role=internal');
    assert.equal(at('/contacts', 'scope=external'), '/contacts?scope=external');
    assert.equal(at('/contacts', 'group=partners'), '/contacts?group=partners');
    assert.equal(at('/contacts', 'search=acme'), '/contacts', 'an unrelated filter keeps the general directory active');
    assert.equal(at('/contacts/parties/12'), '/contacts');
    assert.equal(at('/contacts/create'), '/contacts');
    assert.equal(at('/contacts/create', 'role%5B%5D=client'), '/contacts?role=client', 'Nouveau client highlights Clients');
    assert.equal(at('/contacts/settings'), '/contacts/settings');
    // Legacy URLs highlight the matching view (bookmarks and internal links keep working).
    assert.equal(at('/sales/customers/25'), '/contacts?role=client');
    assert.equal(at('/procurement/suppliers/4'), '/contacts?role=supplier');
});

test('Contacts entries respect permissions: contacts.view sees all, customers.view / suppliers.view keep only their own view', () => {
    const hrefsFor = (permissions: string[]) => {
        const t = tenant(permissions);
        return flat(nav.visibleNavGroups(build('fr', t), t).filter((g: Group) => g.key === 'contacts')).map((i) => i.href);
    };
    assert.deepEqual(hrefsFor(['contacts.view']), CONTACT_VIEWS.map(([href]) => href));
    assert.deepEqual(hrefsFor(['customers.view']), ['/contacts?role=client']);
    assert.deepEqual(hrefsFor(['suppliers.view']), ['/contacts?role=supplier']);
    assert.deepEqual(hrefsFor(['sales_orders.view']), [], 'the module is hidden entirely without any contact permission');
});

test('Contacts labels exist in French and Arabic and breadcrumbs name the module and the view', () => {
    for (const locale of ['fr', 'ar'] as const) {
        const contacts = build(locale, tenant(everyPermission(build('fr', tenant([]))))).find((g) => g.key === 'contacts')!;
        for (const label of [contacts.label, ...contacts.items.map((i) => i.label)]) assert.ok(label && !label.startsWith('MISSING:'), `${locale}: ${label}`);
    }
    assert.notEqual(i18n.ar['nav.group.contacts'], i18n.fr['nav.group.contacts']);
    const groups = full();
    const t = tFor('fr');
    assert.deepEqual(nav.buildBreadcrumbs(groups, '/contacts', t, 'role=client').map((c: any) => c.label), ['Contacts', 'Clients']);
    assert.deepEqual(nav.buildBreadcrumbs(groups, '/contacts', t).map((c: any) => c.label), ['Contacts', 'Tous les contacts']);
    assert.deepEqual(nav.buildBreadcrumbs(groups, '/contacts/parties/5', t).map((c: any) => c.label), ['Contacts', 'Tous les contacts', 'Détail']);
    assert.deepEqual(nav.buildBreadcrumbs(groups, '/contacts/create', t).map((c: any) => c.label), ['Contacts', 'Tous les contacts', 'Création']);
});
