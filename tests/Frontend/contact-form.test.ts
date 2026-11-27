// Run: npm run test:frontend  (this file alone: node --experimental-strip-types --test tests/Frontend/contact-form.test.ts)
//
// The shared contact form (resources/js/components/contacts): pure value / payload / validation logic. One state shape
// serves the full page, the dialog (POS) and the drawer (quotations); these tests pin the contracts it sends to the server.
import assert from 'node:assert/strict';
import path from 'node:path';
import { after, before, test } from 'node:test';
import { createServer, type ViteDevServer } from 'vite';

/* eslint-disable @typescript-eslint/no-explicit-any */
const root = path.resolve(import.meta.dirname, '../..');
let server: ViteDevServer;
let v: any;
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
    v = await server.ssrLoadModule('/resources/js/components/contacts/contactValues.ts');
    i18n = (await server.ssrLoadModule('/resources/js/i18n/translations.ts')) as any;
});

after(async () => {
    await server?.close();
});

const t = (key: string) => i18n.fr[key] ?? `MISSING:${key}`;

test('a simple contact needs only a nature and a name: nothing commercial or fiscal is sent', () => {
    const values = { ...v.blankValues(), display_name: '  Amina  ' };
    const payload = v.toPartyPayload(values, { editing: false });
    assert.equal(payload.kind, 'person');
    assert.equal(payload.display_name, 'Amina');
    assert.equal('roles' in payload, false, 'no role selected: no role fields');
    assert.equal('customer' in payload, false);
    assert.equal('supplier' in payload, false);
    assert.equal(payload.ice, null);
    assert.deepEqual(v.validateValues(values, t, { name: 'Nom' }), {});
    assert.ok(v.validateValues({ ...values, display_name: '   ' }, t, { name: 'Nom' }).display_name);
});

test('context presets only pre-tick roles; every role selection is a plain list the server authorizes', () => {
    assert.deepEqual(v.ROLE_PRESETS.client, ['client']);
    assert.deepEqual(v.ROLE_PRESETS.supplier, ['supplier']);
    assert.deepEqual(v.ROLE_PRESETS.internal, ['internal']);
    assert.deepEqual(v.ROLE_PRESETS.carrier, ['carrier']);
    assert.deepEqual(v.ROLE_PRESETS.interlocutor, ['interlocutor']);
    assert.deepEqual(v.ROLE_PRESETS.contact, [], 'Nouveau contact: no mandatory role');
    const dual = { ...v.blankValues(['client', 'supplier'], 'organization'), display_name: 'ACME' };
    const payload = v.toPartyPayload(dual, { editing: false });
    assert.deepEqual(payload.roles, ['client', 'supplier']);
});

test('Autre sends the typed role name only when ticked and filled; it is validated first', () => {
    const base = { ...v.blankValues(), display_name: 'Karim', other: true, custom_role: '  Installateur ' };
    assert.equal(v.toPartyPayload(base, { editing: false }).custom_role, 'Installateur');
    assert.equal('custom_role' in v.toPartyPayload({ ...base, other: false }, { editing: false }), false);
    assert.ok(v.validateValues({ ...base, custom_role: ' ' }, t, { name: 'Nom' }).custom_role, 'a ticked Autre needs a name');
});

test('commercial conditions are independent per role and only sent for a selected commercial role', () => {
    const values = { ...v.blankValues(['client', 'supplier'], 'organization'), display_name: 'Dual', customer_term_id: '3', supplier_term_id: '5', supplier_currency: 'eur' };
    const payload = v.toPartyPayload(values, { editing: false });
    assert.deepEqual(payload.customer, { payment_term_id: 3 });
    assert.deepEqual(payload.supplier, { payment_term_id: 5, default_currency_code: 'EUR' });
    const clientOnly = v.toPartyPayload({ ...values, role_keys: ['client'] }, { editing: false });
    assert.equal('supplier' in clientOnly, false, 'supplier conditions are never sent without the Fournisseur role');
});

test('an edit always sends the desired role set; an organization sends its interlocutor only on creation', () => {
    const values = { ...v.blankValues([], 'organization'), display_name: 'ACME', contact_person: 'Salma' };
    assert.deepEqual(v.toPartyPayload(values, { editing: true }).roles, []);
    assert.equal('contact_person' in v.toPartyPayload(values, { editing: true }), false);
    assert.equal(v.toPartyPayload(values, { editing: false }).contact_person, 'Salma');
    assert.equal('contact_person' in v.toPartyPayload({ ...values, kind: 'person' }, { editing: false }), false);
});

test('Particulier / Entreprise map to the existing customer contract and keep the LEGACY fiscal identifier (never an ICE)', () => {
    const person = { ...v.blankValues(['client']), display_name: 'Sara', phone: '0600', email: 's@x.test', tax_identifier: 'X-1', address: 'Rue 1' };
    assert.deepEqual(v.toCommercialPayload(person), {
        type: 'individual', display_name: 'Sara', company_name: '', contact_name: '', phone: '0600', email: 's@x.test', tax_identifier: 'X-1', billing_address: 'Rue 1',
    });
    const company = { ...v.blankValues(['client'], 'organization'), display_name: 'Atlas SARL', contact_person: 'Karim', tax_identifier: '001' };
    const payload = v.toCommercialPayload(company);
    assert.equal(payload.type, 'company');
    assert.equal(payload.company_name, 'Atlas SARL');
    assert.equal(payload.display_name, '', 'the server derives the company display name');
    assert.equal(payload.contact_name, 'Karim');
    assert.equal(payload.tax_identifier, '001');
    assert.equal('ice' in payload, false, 'the quick commercial contract has no ICE field');
});

test('editing maps existing roles back to the selection: system roles by key, custom roles by id', () => {
    const party = {
        id: 9, kind: 'organization', display_name: 'ACME', email: 'a@x.test', roles: [
            { id: 1, name: 'Client', system_key: 'client' },
            { id: 41, name: 'Installateur', system_key: null },
        ],
    };
    const values = v.valuesFromParty(party);
    assert.deepEqual(values.role_keys, ['client']);
    assert.deepEqual(values.role_ids, [41]);
    assert.equal(values.kind, 'organization');
    assert.equal(values.email, 'a@x.test');
    assert.equal(values.ice, '', 'absent optional fields become empty strings, sent back as null (cleared deliberately only by the user)');
});

test('quick commercial forms restrict the roles on offer to their own context', () => {
    const roles = [{ system_key: 'client' }, { system_key: 'supplier' }, { system_key: null }];
    assert.deepEqual(v.restrictRoles(roles, ['client']), [{ system_key: 'client' }]);
    assert.equal(v.restrictRoles(roles, undefined).length, 3);
});

test('every contact string resolves in French and Arabic (no raw key, Arabic differs from French)', () => {
    const keys = Object.keys(i18n.fr).filter((k) => k.startsWith('cts.') || k.startsWith('nav.contacts.') || k === 'nav.group.contacts');
    assert.ok(keys.length > 150);
    for (const key of keys) {
        assert.ok(i18n.ar[key], `Arabic missing for ${key}`);
    }
    for (const key of ['cts.title', 'cts.roleOther', 'cts.role.client', 'cts.role.supplier', 'cts.dup.title', 'cts.settings.title']) {
        assert.notEqual(i18n.ar[key], i18n.fr[key], `${key} is not translated`);
    }
    // The eight system roles are labelled by their stable key.
    for (const key of ['client', 'supplier', 'internal', 'provider', 'carrier', 'partner', 'interlocutor', 'prospect']) assert.ok(i18n.fr[`cts.role.${key}`], key);
});
