import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextAreaField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';
import type { FormEvent } from 'react';
import { contactJson, firstErrors } from './api';
import type { ContactRequestError } from './api';
import { blankValues, restrictRoles, toPartyPayload, validateValues, valuesFromParty } from './contactValues';
import DuplicateHints from './DuplicateHints';
import PaymentTermSelector from './PaymentTermSelector';
import RoleSelector from './RoleSelector';
import type { Catalogue, ContactCan, ContactResult, ContactValues, DuplicateCandidate, FieldErrors, Kind, PartyForEdit } from './types';

export type ContactFormProps = {
    /** quick = essentials only (transaction contexts); complete = every identity section. */
    mode: 'quick' | 'complete';
    catalogue: Catalogue;
    can: ContactCan;
    /** Edit an existing identity. Without it the form creates one. */
    party?: PartyForEdit | null;
    presetRoles?: string[];
    /** Quick commercial contexts may restrict the roles on offer (e.g. only Client). */
    allowedRoles?: string[];
    /** Particulier / Entreprise (POS, quotations) or Personne / Organisation. */
    natureLabels?: 'commercial' | 'neutral';
    /** Value of the fiscal field in quick commercial forms: the legacy customer tax identifier, never an ICE. */
    legacyFiscal?: boolean;
    /** Page = Inertia navigation. Overlay = JSON, the page underneath keeps its state. */
    layout: 'page' | 'overlay';
    /** Replaces the default endpoint (the POS and quotation transactional contracts). */
    transport?: (values: ContactValues) => Promise<ContactResult>;
    initialValues?: Partial<ContactValues>;
    submitLabel?: string;
    formId?: string;
    /** The wrapper renders its own footer buttons (drawer / dialog footers) and wires them with form={formId}. */
    hideActions?: boolean;
    onBusyChange?: (busy: boolean) => void;
    onSuccess?: (result: ContactResult) => void;
    onCancel?: () => void;
};

const Segment = ({ value, onChange, options, label }: { value: Kind; onChange: (k: Kind) => void; options: { value: Kind; label: string }[]; label: string }) => (
    <div role="group" aria-label={label} className="inline-flex rounded-field border border-line-strong bg-surface p-0.5">
        {options.map((option) => (
            <button
                key={option.value}
                type="button"
                aria-pressed={value === option.value}
                onClick={() => onChange(option.value)}
                className={`min-h-9 rounded-[calc(var(--radius-field,0.5rem)-2px)] px-4 text-sm font-medium transition-soft ${value === option.value ? 'bg-primary text-primary-fg' : 'text-ink-muted hover:text-ink'}`}
            >
                {option.label}
            </button>
        ))}
    </div>
);

/**
 * The ONE contact form: creation, edition, customer / supplier / internal / carrier / custom-role entry points, in a page, a dialog
 * or a drawer. The wrapper only chooses where it is shown; state, validation, role selection and the submit workflow live here.
 * Identity is shared: whatever the entry point, a single canonical contact is written (the server decides authorization).
 */
export default function ContactForm(props: ContactFormProps) {
    const { mode, catalogue, can, party, presetRoles = [], allowedRoles, natureLabels = 'neutral', legacyFiscal = false, layout, transport, initialValues, onSuccess, onCancel } = props;
    const { t } = useI18n();
    const autoId = useId();
    const formId = props.formId ?? `contact-form-${autoId}`;
    const editing = Boolean(party);
    const [values, setValues] = useState<ContactValues>(() => ({ ...(party ? valuesFromParty(party) : blankValues(presetRoles)), ...initialValues }));
    const [errors, setErrors] = useState<FieldErrors>({});
    const [formError, setFormError] = useState('');
    const [busy, setBusy] = useState(false);
    const [more, setMore] = useState(false);
    const complete = mode === 'complete';
    const org = values.kind === 'organization';
    const set = <K extends keyof ContactValues>(field: K, value: ContactValues[K]) => setValues((previous) => ({ ...previous, [field]: value }));
    const patch = (next: Partial<ContactValues>) => setValues((previous) => ({ ...previous, ...next }));

    useEffect(() => props.onBusyChange?.(busy), [busy]); // eslint-disable-line react-hooks/exhaustive-deps

    const labels = {
        person: t(natureLabels === 'commercial' ? 'cts.kind.individual' : 'cts.kind.person'),
        organization: t(natureLabels === 'commercial' ? 'cts.kind.company' : 'cts.kind.organization'),
    };
    const nameLabel = org ? t('cts.legalName') : t('cts.fullName');

    function reuse(candidate: DuplicateCandidate) {
        // Reusing keeps the existing identity untouched and only adds the roles ticked here.
        setValues((previous) => ({ ...previous, reuse_party_id: candidate.id, display_name: previous.display_name || candidate.display_name }));
    }

    function applyServerErrors(reason: unknown) {
        const error = reason as ContactRequestError;
        const fields = firstErrors(error.errors);
        const known = ['display_name', 'email', 'phone', 'kind', 'custom_role', 'ice', 'rc_number', 'tax_identifier', 'whatsapp_username', 'country_code'];
        setErrors(Object.fromEntries(Object.entries(fields).filter(([key]) => known.includes(key))));
        const rest = Object.entries(fields).filter(([key]) => !known.includes(key)).map(([, message]) => message);
        setFormError(rest[0] ?? (error.status === 403 ? t('cts.forbidden') : error.errors ? '' : t('cts.saveFailed')));
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (busy) return;
        setFormError('');
        const local = validateValues(values, t as unknown as (key: string) => string, { name: nameLabel });
        setErrors(local);
        if (Object.keys(local).length > 0) return;
        setBusy(true);

        if (layout === 'page' && !transport) {
            const payload = toPartyPayload(values, { editing }) as Record<string, never>;
            const options = {
                onError: (serverErrors: Record<string, string>) => {
                    applyServerErrors({ errors: serverErrors });
                    setBusy(false);
                },
                onFinish: () => setBusy(false),
            };
            if (party) router.patch(`/contacts/parties/${party.id}`, payload, options);
            else router.post('/contacts/parties', payload, options);

            return;
        }

        try {
            const result = transport
                ? await transport(values)
                : (await contactJson<{ data: ContactResult }>(party ? `/contacts/parties/${party.id}` : '/contacts/parties', party ? 'PATCH' : 'POST', toPartyPayload(values, { editing }))).data;
            onSuccess?.(result);
        } catch (reason) {
            applyServerErrors(reason);
        } finally {
            setBusy(false);
        }
    }

    const roleError = errors.custom_role;
    const showRoles = complete || (allowedRoles !== undefined && allowedRoles.length > 1);
    const commercialClient = values.role_keys.includes('client');
    const commercialSupplier = values.role_keys.includes('supplier');
    const roles = restrictRoles(catalogue.roles, allowedRoles);

    return (
        <form id={formId} onSubmit={submit} noValidate className="space-y-5" aria-label={editing ? t('cts.edit') : t('cts.new')}>
            {formError && <Alert tone="danger">{formError}</Alert>}

            {/* A. Nature */}
            <div>
                <p className="mb-1.5 text-sm font-medium text-ink">{t('cts.nature')}</p>
                <Segment
                    label={t('cts.nature')}
                    value={values.kind}
                    onChange={(kind) => set('kind', kind)}
                    options={[
                        { value: 'person', label: labels.person },
                        { value: 'organization', label: labels.organization },
                    ]}
                />
            </div>

            {/* B. Essential identity: only the name is required. */}
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    className="sm:col-span-2"
                    label={nameLabel}
                    value={values.display_name}
                    onChange={(event) => set('display_name', event.target.value)}
                    error={errors.display_name}
                    required
                    autoComplete="off"
                    maxLength={255}
                    data-autofocus
                />
                <TextField label={t('cts.phone')} type="tel" dir="ltr" value={values.phone} onChange={(event) => set('phone', event.target.value)} error={errors.phone} maxLength={64} />
                <TextField label={t('cts.email')} type="email" dir="ltr" value={values.email} onChange={(event) => set('email', event.target.value)} error={errors.email} maxLength={254} />
                {org && !editing && (
                    <TextField className="sm:col-span-2" label={t('cts.contactPerson')} hint={t('cts.contactPersonHelp')} value={values.contact_person} onChange={(event) => set('contact_person', event.target.value)} maxLength={255} />
                )}
            </div>

            {!editing && (
                <DuplicateHints
                    values={values}
                    enabled
                    canReuse={can.update}
                    onReuse={reuse}
                    onStopReusing={() => set('reuse_party_id', null)}
                    allowOpen={layout === 'page'}
                />
            )}

            {/* C + D. Roles, with Autre */}
            {showRoles && (
                <RoleSelector
                    roles={roles}
                    values={values}
                    can={can}
                    error={roleError}
                    onChange={patch}
                    allowedKeys={allowedRoles}
                    allowOther={complete}
                />
            )}

            {/* E. Additional information, progressively disclosed */}
            <div>
                <button type="button" aria-expanded={more} onClick={() => setMore((open) => !open)} className="inline-flex min-h-9 items-center gap-1.5 text-sm font-medium text-accent hover:underline">
                    <span aria-hidden="true">{more ? '−' : '+'}</span>
                    {t('cts.moreDetails')}
                </button>
                {more && (
                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                        {complete && org && (
                            <TextField className="sm:col-span-2" label={t('cts.tradeName')} value={values.legal_name} onChange={(event) => set('legal_name', event.target.value)} maxLength={255} />
                        )}
                        <TextField label={t('cts.whatsapp')} type="tel" dir="ltr" value={values.whatsapp} onChange={(event) => set('whatsapp', event.target.value)} maxLength={64} />
                        {complete && (
                            <TextField label={t('cts.whatsappUsername')} dir="ltr" value={values.whatsapp_username} onChange={(event) => set('whatsapp_username', event.target.value)} error={errors.whatsapp_username} maxLength={64} />
                        )}
                        <TextAreaField className="sm:col-span-2" label={t('cts.address')} rows={2} value={values.address} onChange={(event) => set('address', event.target.value)} maxLength={5000} />
                        {complete && (
                            <>
                                <TextField label={t('cts.city')} value={values.city} onChange={(event) => set('city', event.target.value)} maxLength={120} />
                                <TextField label={t('cts.country')} hint={t('cts.countryHelp')} dir="ltr" value={values.country_code} onChange={(event) => set('country_code', event.target.value.toUpperCase())} error={errors.country_code} maxLength={2} />
                            </>
                        )}
                        {legacyFiscal ? (
                            <TextField className="sm:col-span-2" label={t('cts.fiscalLegacy')} hint={t('cts.fiscalLegacyHelp')} value={values.tax_identifier} onChange={(event) => set('tax_identifier', event.target.value)} maxLength={128} />
                        ) : (
                            complete && (
                                <fieldset className="grid gap-4 sm:col-span-2 sm:grid-cols-3">
                                    <legend className="mb-1 text-sm font-medium text-ink">{t('cts.fiscal')}</legend>
                                    <TextField label={t('cts.ice')} dir="ltr" value={values.ice} onChange={(event) => set('ice', event.target.value)} error={errors.ice} maxLength={32} />
                                    <TextField label={t('cts.if')} dir="ltr" value={values.tax_identifier} onChange={(event) => set('tax_identifier', event.target.value)} error={errors.tax_identifier} maxLength={128} />
                                    <TextField label={t('cts.rc')} dir="ltr" value={values.rc_number} onChange={(event) => set('rc_number', event.target.value)} error={errors.rc_number} maxLength={32} />
                                </fieldset>
                            )
                        )}
                        {complete && <TextAreaField className="sm:col-span-2" label={t('cts.notes')} rows={3} value={values.notes} onChange={(event) => set('notes', event.target.value)} maxLength={5000} />}
                    </div>
                )}
            </div>

            {/* F. Commercial conditions: one clearly identified block per commercial role, never shared. */}
            {complete && !editing && (commercialClient || commercialSupplier) && (
                <div className="space-y-4">
                    {commercialClient && (
                        <section className="rounded-panel border border-line p-4" aria-label={t('cts.customerConditions')}>
                            <h3 className="mb-3 text-sm font-semibold text-ink">{t('cts.customerConditions')}</h3>
                            {can.assignCustomerTerms ? (
                                <PaymentTermSelector terms={catalogue.paymentTerms} value={values.customer_term_id} onChange={(v) => set('customer_term_id', v)} label={t('cts.customerTerm')} />
                            ) : (
                                <p className="text-[13px] text-ink-muted">{t('cts.noTermRight')}</p>
                            )}
                            <p className="mt-2 text-[13px] text-ink-muted">{t('cts.creditInFinance')}</p>
                        </section>
                    )}
                    {commercialSupplier && (
                        <section className="rounded-panel border border-line p-4" aria-label={t('cts.supplierConditions')}>
                            <h3 className="mb-3 text-sm font-semibold text-ink">{t('cts.supplierConditions')}</h3>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {can.assignSupplierTerms && (
                                    <PaymentTermSelector terms={catalogue.paymentTerms} value={values.supplier_term_id} onChange={(v) => set('supplier_term_id', v)} label={t('cts.supplierTerm')} />
                                )}
                                <TextField label={t('cts.supplierCurrency')} dir="ltr" maxLength={3} value={values.supplier_currency} onChange={(event) => set('supplier_currency', event.target.value.toUpperCase())} />
                            </div>
                        </section>
                    )}
                </div>
            )}

            {!props.hideActions && (
                <div className={`flex flex-wrap justify-end gap-2 ${layout === 'overlay' ? 'sticky bottom-0 -mx-1 border-t border-line bg-surface px-1 pt-3' : ''}`}>
                    {onCancel && (
                        <Button type="button" variant="secondary" onClick={onCancel} disabled={busy}>
                            {t('common.cancel')}
                        </Button>
                    )}
                    <Button type="submit" loading={busy} loadingText={t('cts.saving')}>
                        {props.submitLabel ?? (editing ? t('cts.save') : values.reuse_party_id ? t('cts.addRolesToExisting') : t('cts.create'))}
                    </Button>
                </div>
            )}
        </form>
    );
}
