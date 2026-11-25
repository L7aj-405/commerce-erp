/** Shapes served by the unified Contacts backend (Phase 1 contracts + PartyController). Never invent fields here. */
export type Role = { id: number; name: string; system_key: string | null; is_system?: boolean };
export type PaymentTermOption = { id: number; name: string; days: number };
export type Catalogue = { roles: Role[]; paymentTerms: PaymentTermOption[] };
export type ContactCan = {
    create: boolean;
    update: boolean;
    archive: boolean;
    manageRoles: boolean;
    createCustomer: boolean;
    createSupplier: boolean;
    manageTerms: boolean;
    /** finance.customers.terms.manage / suppliers.manage: who may ATTACH a catalogue term to a profile. */
    assignCustomerTerms: boolean;
    assignSupplierTerms: boolean;
    viewCustomers: boolean;
    viewSuppliers: boolean;
    viewContacts?: boolean;
};

export type Kind = 'person' | 'organization';

/** Complete form state. One shape for every entry point (page, dialog, drawer; quick or complete). */
export type ContactValues = {
    kind: Kind;
    display_name: string;
    legal_name: string;
    email: string;
    phone: string;
    whatsapp: string;
    whatsapp_username: string;
    address: string;
    city: string;
    country_code: string;
    ice: string;
    tax_identifier: string;
    rc_number: string;
    notes: string;
    contact_person: string;
    role_keys: string[];
    role_ids: number[];
    other: boolean;
    custom_role: string;
    customer_term_id: string;
    supplier_term_id: string;
    supplier_currency: string;
    reuse_party_id: number | null;
};

/** An existing party as served to the edit form (PartyIdentitySerializer + role_ids). */
export type PartyForEdit = Partial<Omit<ContactValues, 'role_keys' | 'role_ids'>> & {
    id: number;
    display_name: string;
    kind: Kind;
    roles: Role[];
    role_ids?: number[];
};

export type ContactResult = {
    party_id: number;
    display_name: string;
    reused: boolean;
    customer: { id: number; type: string; display_name: string; company_name: string | null; phone: string | null; email: string | null; tax_identifier: string | null; billing_address: string | null } | null;
    supplier: { id: number; name: string } | null;
};

export type DuplicateCandidate = {
    id: number;
    display_name: string;
    kind: Kind;
    status: string;
    roles: string[];
    strength: 'strong' | 'weak';
    reasons: string[];
};

export type FieldErrors = Record<string, string>;
