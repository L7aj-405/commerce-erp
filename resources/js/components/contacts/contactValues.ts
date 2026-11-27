import type { ContactValues, FieldErrors, Kind, PartyForEdit, Role } from './types';

/** The system role keys that create a commercial profile on the server. */
export const COMMERCIAL_ROLE_KEYS = ['client', 'supplier'] as const;

export function blankValues(presetRoles: string[] = [], kind: Kind = 'person'): ContactValues {
    return {
        kind,
        display_name: '',
        legal_name: '',
        email: '',
        phone: '',
        whatsapp: '',
        whatsapp_username: '',
        address: '',
        city: '',
        country_code: '',
        ice: '',
        tax_identifier: '',
        rc_number: '',
        notes: '',
        contact_person: '',
        role_keys: [...presetRoles],
        role_ids: [],
        other: false,
        custom_role: '',
        customer_term_id: '',
        supplier_term_id: '',
        supplier_currency: '',
        reuse_party_id: null,
    };
}

/** Existing roles become the selection: system roles by key, organization custom roles by id. */
export function valuesFromParty(party: PartyForEdit): ContactValues {
    const base = blankValues([], party.kind);
    const text = (value: string | null | undefined) => value ?? '';

    return {
        ...base,
        display_name: party.display_name,
        legal_name: text(party.legal_name),
        email: text(party.email),
        phone: text(party.phone),
        whatsapp: text(party.whatsapp),
        whatsapp_username: text(party.whatsapp_username),
        address: text(party.address),
        city: text(party.city),
        country_code: text(party.country_code),
        ice: text(party.ice),
        tax_identifier: text(party.tax_identifier),
        rc_number: text(party.rc_number),
        notes: text(party.notes),
        role_keys: party.roles.filter((role: Role) => role.system_key).map((role) => role.system_key as string),
        role_ids: party.roles.filter((role: Role) => !role.system_key).map((role) => role.id),
    };
}

const clean = (value: string) => value.trim();
const orNull = (value: string) => (clean(value) === '' ? null : clean(value));

/**
 * Body of POST /contacts/parties and PATCH /contacts/parties/{id}.
 * Empty optional fields are sent as null so an edit can clear them; role fields are always sent on edit (the selection is the
 * desired set) and only when something is chosen on create.
 */
export function toPartyPayload(values: ContactValues, { editing }: { editing: boolean }) {
    const payload: Record<string, unknown> = {
        kind: values.kind,
        display_name: clean(values.display_name),
        legal_name: orNull(values.legal_name),
        email: orNull(values.email),
        phone: orNull(values.phone),
        whatsapp: orNull(values.whatsapp),
        whatsapp_username: orNull(values.whatsapp_username),
        address: orNull(values.address),
        city: orNull(values.city),
        country_code: orNull(values.country_code),
        ice: orNull(values.ice),
        tax_identifier: orNull(values.tax_identifier),
        rc_number: orNull(values.rc_number),
        notes: orNull(values.notes),
    };
    if (editing || values.role_keys.length > 0 || values.role_ids.length > 0) {
        payload.roles = values.role_keys;
        payload.role_ids = values.role_ids;
    }
    if (values.other && clean(values.custom_role) !== '') payload.custom_role = clean(values.custom_role);
    if (!editing && values.kind === 'organization' && clean(values.contact_person) !== '') payload.contact_person = clean(values.contact_person);
    if (values.reuse_party_id) payload.reuse_party_id = values.reuse_party_id;
    if (values.role_keys.includes('client') && values.customer_term_id) payload.customer = { payment_term_id: Number(values.customer_term_id) };
    if (values.role_keys.includes('supplier')) {
        const supplier: Record<string, unknown> = {};
        if (values.supplier_term_id) supplier.payment_term_id = Number(values.supplier_term_id);
        if (clean(values.supplier_currency) !== '') supplier.default_currency_code = clean(values.supplier_currency).toUpperCase();
        if (Object.keys(supplier).length > 0) payload.supplier = supplier;
    }

    return payload;
}

/**
 * Body of the existing transactional endpoints (POST /pos/customers, POST /quotations/customers): the backward-compatible
 * customer contract. "Particulier / Entreprise" map to person / organization. In these commercial quick forms the single
 * fiscal identifier field keeps its LEGACY customers.tax_identifier meaning: it is never relabelled or stored as an ICE.
 */
export function toCommercialPayload(values: ContactValues) {
    const company = values.kind === 'organization';

    return {
        type: company ? 'company' : 'individual',
        display_name: company ? '' : clean(values.display_name),
        company_name: company ? clean(values.display_name) : '',
        contact_name: company ? clean(values.contact_person) : '',
        phone: clean(values.phone),
        email: clean(values.email),
        tax_identifier: clean(values.tax_identifier),
        billing_address: clean(values.address),
    };
}

type Translate = (key: string) => string;

/** Client-side mirror of the server minimum: a name, and a role name when "Autre" is ticked. Never fiscal or financial data. */
export function validateValues(values: ContactValues, t: Translate, labels: { name: string }): FieldErrors {
    const errors: FieldErrors = {};
    const required = t('common.required');
    if (clean(values.display_name) === '') errors.display_name = `${labels.name} : ${required}`;
    if (values.other && clean(values.custom_role) === '') errors.custom_role = required;
    if (clean(values.email) !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(clean(values.email))) errors.email = t('cts.invalidEmail');

    return errors;
}

/** Roles a commercial quick form may offer: it never lets the user stray from its context. */
export function restrictRoles<T extends { system_key: string | null }>(roles: T[], allowed: string[] | undefined): T[] {
    return allowed ? roles.filter((role) => role.system_key !== null && allowed.includes(role.system_key)) : roles;
}

/** Entry points -> preset roles. The server still authorizes every role; presets only pre-tick the form. */
export const ROLE_PRESETS = {
    contact: [] as string[],
    client: ['client'],
    supplier: ['supplier'],
    internal: ['internal'],
    carrier: ['carrier'],
    interlocutor: ['interlocutor'],
} as const;
