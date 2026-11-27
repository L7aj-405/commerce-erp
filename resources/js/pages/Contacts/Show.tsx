import PaymentTermSelector from '@/components/contacts/PaymentTermSelector';
import RoleBadges from '@/components/contacts/RoleBadges';
import RoleSelector from '@/components/contacts/RoleSelector';
import type { Catalogue, ContactCan, ContactValues, PaymentTermOption, Role } from '@/components/contacts/types';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink } from '@/components/ui/Button';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { SelectField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { Badge } from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

type Party = {
    id: number;
    kind: 'person' | 'organization';
    display_name: string;
    legal_name: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    whatsapp_username: string | null;
    address: string | null;
    city: string | null;
    country_code: string | null;
    notes: string | null;
    status: 'active' | 'archived';
    ice?: string | null;
    tax_identifier?: string | null;
    rc_number?: string | null;
    customer_id: number | null;
    supplier_id: number | null;
    roles: Role[];
};
type Relationship = { id: number; type: string; job_title: string | null; is_primary: boolean; direction: 'works_at' | 'has_person'; party: { id: number; display_name: string; kind: string; email: string | null; phone: string | null } };
type CustomerTab = {
    id: number;
    status: string;
    type: string;
    links: { sheet: string; financial_account?: string };
    terms?: { payment_term_id: number | null; payment_term_days: number | null; credit_enabled: boolean; credit_limit: string | null; invoicing_policy: string | null } | null;
};
type SupplierTab = { id: number; active: boolean; payment_term_id: number | null; default_payment_term_days: number | null; default_currency_code: string | null; links: { sheet: string } };
type Activity = { id: number; event: string; created_at: string; actor: string | null };
type Props = {
    party: Party;
    inactiveRoles: Role[];
    relationships: Relationship[];
    customer: CustomerTab | null;
    supplier: SupplierTab | null;
    activity: Activity[];
    blockers: string[];
    whatsappDestination: { available: boolean; link: string | null };
    tab?: string | null;
    catalogue: Catalogue;
    can: ContactCan;
};

type TabKey = 'info' | 'relations' | 'client' | 'supplier' | 'activity';

/** Run an Inertia visit and resolve when it finishes, so role changes can be applied one after another. */
const visit = (method: 'post' | 'delete', url: string, data: Record<string, unknown> = {}) =>
    new Promise<void>((resolve) => router[method](url, data as never, { preserveScroll: true, preserveState: true, onFinish: () => resolve() }));

export default function ContactShow({ party, inactiveRoles, relationships, customer, supplier, activity, blockers, whatsappDestination, tab, catalogue, can }: Props) {
    const { t, direction } = useI18n();
    const confirm = useConfirm();
    const tabs: { key: TabKey; label: string }[] = [
        { key: 'info', label: t('cts.tab.info') },
        { key: 'relations', label: t('cts.tab.relations') },
        ...(customer ? [{ key: 'client' as const, label: t('cts.tab.client') }] : []),
        ...(supplier ? [{ key: 'supplier' as const, label: t('cts.tab.supplier') }] : []),
        { key: 'activity', label: t('cts.tab.activity') },
    ];
    const [active, setActive] = useState<TabKey>(tabs.some((x) => x.key === tab) ? (tab as TabKey) : 'info');
    const archived = party.status === 'archived';

    async function toggleArchive() {
        const ok = await confirm({
            title: t(archived ? 'cts.restoreTitle' : 'cts.archiveTitle'),
            description: t(archived ? 'cts.restoreHelp' : 'cts.archiveHelp'),
            confirmLabel: t(archived ? 'cts.restore' : 'cts.archive'),
            danger: !archived,
        });
        if (ok) router.post(`/contacts/parties/${party.id}/${archived ? 'restore' : 'archive'}`, {}, { preserveScroll: true });
    }

    return (
        <ApplicationShell>
            <Head title={party.display_name} />
            <Link href="/contacts" className="text-sm text-ink-muted">
                {direction === 'rtl' ? '→' : '←'} {t('cts.title')}
            </Link>
            <PageHeader
                title={party.display_name}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        <Badge variant="neutral" dot={false}>
                            {t(party.kind === 'person' ? 'cts.kind.person' : 'cts.kind.organization')}
                        </Badge>
                        <RoleBadges roles={party.roles} />
                        <Badge variant={archived ? 'neutral' : 'success'}>{t(archived ? 'cts.status.archived' : 'cts.status.active')}</Badge>
                    </span>
                }
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.update && !archived && (
                            <ButtonLink href={`/contacts/parties/${party.id}/edit`} variant="outline">
                                {t('cts.edit')}
                            </ButtonLink>
                        )}
                        {can.archive && (
                            <Button variant={archived ? 'outline' : 'ghost'} onClick={toggleArchive}>
                                {t(archived ? 'cts.restore' : 'cts.archive')}
                            </Button>
                        )}
                    </div>
                }
            />
            <p className="-mt-2 mb-4 flex flex-wrap gap-x-5 gap-y-1 text-sm text-ink-muted">
                {party.phone && <bdi dir="ltr">{party.phone}</bdi>}
                {party.email && <bdi>{party.email}</bdi>}
                {whatsappDestination.available && whatsappDestination.link && (
                    <a href={whatsappDestination.link} target="_blank" rel="noreferrer" className="text-accent underline">
                        WhatsApp
                    </a>
                )}
            </p>
            {archived && <Alert tone="info" className="mb-4">{t('cts.archivedBanner')}</Alert>}

            <div role="tablist" aria-label={party.display_name} className="mb-4 flex gap-1 overflow-x-auto border-b border-line">
                {tabs.map((item) => (
                    <button
                        key={item.key}
                        role="tab"
                        id={`tab-${item.key}`}
                        aria-selected={active === item.key}
                        aria-controls={`panel-${item.key}`}
                        type="button"
                        onClick={() => setActive(item.key)}
                        className={`-mb-px min-h-10 shrink-0 border-b-2 px-4 text-sm font-medium transition-soft ${active === item.key ? 'border-accent text-ink' : 'border-transparent text-ink-muted hover:text-ink'}`}
                    >
                        {item.label}
                    </button>
                ))}
            </div>

            <div role="tabpanel" id={`panel-${active}`} aria-labelledby={`tab-${active}`}>
                {active === 'info' && <InfoTab party={party} inactiveRoles={inactiveRoles} blockers={blockers} catalogue={catalogue} can={can} archived={archived} />}
                {active === 'relations' && <RelationsTab party={party} relationships={relationships} can={can} archived={archived} />}
                {active === 'client' && customer && <ClientTab party={party} customer={customer} terms={catalogue.paymentTerms} can={can} archived={archived} />}
                {active === 'supplier' && supplier && <SupplierTab party={party} supplier={supplier} terms={catalogue.paymentTerms} can={can} archived={archived} />}
                {active === 'activity' && <ActivityTab activity={activity} />}
            </div>
        </ApplicationShell>
    );
}

const Card = ({ title, children }: { title?: string; children: ReactNode }) => (
    <section className="surface-card mb-4 p-5">
        {title && <h2 className="mb-3 text-sm font-semibold text-ink">{title}</h2>}
        {children}
    </section>
);

function Facts({ rows }: { rows: [string, ReactNode][] }) {
    const shown = rows.filter(([, value]) => value !== null && value !== undefined && value !== '');
    if (shown.length === 0) return <p className="text-sm text-ink-muted">—</p>;

    return (
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            {shown.map(([label, value]) => (
                <div key={label} className="min-w-0">
                    <dt className="text-[13px] text-ink-faint">{label}</dt>
                    <dd className="break-words text-ink">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function InfoTab({ party, inactiveRoles, blockers, catalogue, can, archived }: { party: Party; inactiveRoles: Role[]; blockers: string[]; catalogue: Catalogue; can: ContactCan; archived: boolean }) {
    const { t } = useI18n();
    const initial = { role_keys: party.roles.filter((r) => r.system_key).map((r) => r.system_key as string), role_ids: party.roles.filter((r) => !r.system_key).map((r) => r.id), other: false, custom_role: '' };
    const [roles, setRoles] = useState(initial);
    const [saving, setSaving] = useState(false);
    const changed = JSON.stringify([[...roles.role_keys].sort(), [...roles.role_ids].sort(), roles.other ? roles.custom_role.trim() : '']) !== JSON.stringify([[...initial.role_keys].sort(), [...initial.role_ids].sort(), '']);

    /** Adds first (commercial roles reuse or create their profile), then soft-removes: the profile is never deleted. */
    async function applyRoles() {
        setSaving(true);
        const addKeys = roles.role_keys.filter((k) => !initial.role_keys.includes(k));
        const addIds = roles.role_ids.filter((id) => !initial.role_ids.includes(id));
        const custom = roles.other ? roles.custom_role.trim() : '';
        if (addKeys.length || addIds.length || custom) await visit('post', `/contacts/parties/${party.id}/roles`, { roles: addKeys, role_ids: addIds, ...(custom ? { custom_role: custom } : {}) });
        for (const key of initial.role_keys.filter((k) => !roles.role_keys.includes(k))) {
            const role = party.roles.find((r) => r.system_key === key);
            if (role) await visit('delete', `/contacts/parties/${party.id}/roles/${role.id}`);
        }
        for (const id of initial.role_ids.filter((x) => !roles.role_ids.includes(x))) await visit('delete', `/contacts/parties/${party.id}/roles/${id}`);
        setSaving(false);
    }

    return (
        <>
            <Card title={t('cts.identity')}>
                <Facts
                    rows={[
                        [t('cts.legalName'), party.kind === 'organization' ? party.display_name : null],
                        [t('cts.tradeName'), party.legal_name && party.legal_name !== party.display_name ? party.legal_name : null],
                        [t('cts.phone'), party.phone ? <bdi dir="ltr" key="p">{party.phone}</bdi> : null],
                        [t('cts.email'), party.email],
                        [t('cts.whatsapp'), party.whatsapp ? <bdi dir="ltr" key="w">{party.whatsapp}</bdi> : null],
                        [t('cts.whatsappUsername'), party.whatsapp_username ? `@${party.whatsapp_username}` : null],
                        [t('cts.address'), [party.address, party.city, party.country_code].filter(Boolean).join(', ')],
                        [t('cts.ice'), party.ice],
                        [t('cts.if'), party.tax_identifier],
                        [t('cts.rc'), party.rc_number],
                        [t('cts.notes'), party.notes],
                    ]}
                />
            </Card>

            {can.update && !archived && (
                <Card title={t('cts.manageRoles')}>
                    {blockers.length > 0 && (
                        <Alert tone="warning" className="mb-3" title={t('cts.protected')}>
                            {blockers.map((code) => t(`cts.blocker.${code}` as never)).join(' · ')}
                        </Alert>
                    )}
                    <RoleSelector roles={catalogue.roles} values={roles} can={can} onChange={(patch) => setRoles((current) => ({ ...current, ...patch }))} />
                    {inactiveRoles.length > 0 && (
                        <p className="mt-3 text-[13px] text-ink-muted">
                            {t('cts.inactiveRoles')} : {inactiveRoles.map((r) => (r.system_key ? t(`cts.role.${r.system_key}` as never) : r.name)).join(', ')}
                        </p>
                    )}
                    <div className="mt-4 flex justify-end">
                        <Button onClick={applyRoles} loading={saving} loadingText={t('cts.saving')} disabled={!changed}>
                            {t('cts.saveRoles')}
                        </Button>
                    </div>
                </Card>
            )}
        </>
    );
}

function RelationsTab({ party, relationships, can, archived }: { party: Party; relationships: Relationship[]; can: ContactCan; archived: boolean }) {
    const { t } = useI18n();
    const [form, setForm] = useState({ display_name: '', email: '', phone: '', type: 'interlocutor', job_title: '' });
    const [busy, setBusy] = useState(false);
    const typeLabel = (type: string) => t(`cts.relType.${type}` as never);
    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!form.display_name.trim()) return;
        setBusy(true);
        await visit('post', `/contacts/parties/${party.id}/relationships`, form);
        setBusy(false);
        setForm({ display_name: '', email: '', phone: '', type: 'interlocutor', job_title: '' });
    };

    return (
        <>
            <Card title={party.kind === 'organization' ? t('cts.tab.relations') : t('cts.worksAt')}>
                {relationships.length === 0 ? (
                    <p className="text-sm text-ink-muted">{t('cts.noRelations')}</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {relationships.map((rel) => (
                            <li key={rel.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                <span className="min-w-0">
                                    <Link href={`/contacts/parties/${rel.party.id}`} className="font-medium text-ink hover:underline">
                                        {rel.party.display_name}
                                    </Link>
                                    <span className="ms-2 text-[13px] text-ink-muted">{[typeLabel(rel.type), rel.job_title].filter(Boolean).join(' · ')}</span>
                                </span>
                                <span className="text-[13px] text-ink-muted">{[rel.party.email, rel.party.phone].filter(Boolean).join(' · ')}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            {can.update && !archived && party.kind === 'organization' && (
                <Card title={t('cts.addInterlocutor')}>
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
                        <TextField label={t('cts.fullName')} value={form.display_name} onChange={(e) => setForm({ ...form, display_name: e.target.value })} required />
                        <SelectField label={t('cts.relationType')} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                            {['interlocutor', 'employee', 'representative', 'owner'].map((type) => (
                                <option key={type} value={type}>
                                    {typeLabel(type)}
                                </option>
                            ))}
                        </SelectField>
                        <TextField label={t('cts.jobTitle')} value={form.job_title} onChange={(e) => setForm({ ...form, job_title: e.target.value })} maxLength={255} />
                        <TextField label={t('cts.phone')} dir="ltr" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                        <TextField className="sm:col-span-2" label={t('cts.email')} type="email" dir="ltr" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                        <div className="flex justify-end sm:col-span-2">
                            <Button type="submit" loading={busy} loadingText={t('cts.saving')}>
                                {t('cts.addInterlocutor')}
                            </Button>
                        </div>
                    </form>
                </Card>
            )}
        </>
    );
}

function TermEditor({ party, profile, terms, current, can, archived }: { party: Party; profile: 'customer' | 'supplier'; terms: PaymentTermOption[]; current: number | null; can: boolean; archived: boolean }) {
    const { t } = useI18n();
    const [value, setValue] = useState(current ? String(current) : '');
    const [busy, setBusy] = useState(false);
    const save = async () => {
        setBusy(true);
        await visit('post', `/contacts/parties/${party.id}/payment-terms`, { profile, payment_term_id: value === '' ? null : Number(value) });
        setBusy(false);
    };

    return (
        <div className="max-w-sm">
            <PaymentTermSelector terms={terms} value={value} onChange={setValue} label={t('cts.paymentTerm')} disabled={!can || archived} />
            {can && !archived && (
                <div className="mt-3">
                    <Button size="sm" onClick={save} loading={busy} loadingText={t('cts.saving')} disabled={value === (current ? String(current) : '')}>
                        {t('cts.save')}
                    </Button>
                </div>
            )}
        </div>
    );
}

function ClientTab({ party, customer, terms, can, archived }: { party: Party; customer: CustomerTab; terms: PaymentTermOption[]; can: ContactCan; archived: boolean }) {
    const { t } = useI18n();
    const policy = customer.terms?.invoicing_policy;

    return (
        <>
            <Card title={t('cts.tab.client')}>
                <Facts
                    rows={[
                        [t('cts.customerType'), t(customer.type === 'company' ? 'cts.kind.company' : 'cts.kind.individual')],
                        [t('common.status'), t(customer.status === 'active' ? 'cts.status.active' : 'cts.status.inactive')],
                        [t('cts.invoicingPolicy'), policy ? t(`cts.policy.${policy}` as never) : customer.terms ? t('cts.policy.default') : null],
                        [t('cts.credit'), customer.terms ? (customer.terms.credit_enabled ? `${t('cts.creditOn')}${customer.terms.credit_limit ? ` · ${customer.terms.credit_limit}` : ''}` : t('cts.creditOff')) : null],
                    ]}
                />
                <div className="mt-4 flex flex-wrap gap-2">
                    <ButtonLink href={customer.links.sheet} variant="outline" size="sm">
                        {t('cts.customerSheet')}
                    </ButtonLink>
                    {customer.links.financial_account && (
                        <ButtonLink href={customer.links.financial_account} variant="outline" size="sm">
                            {t('cts.financialAccount')}
                        </ButtonLink>
                    )}
                </div>
                <p className="mt-3 text-[13px] text-ink-muted">{t('cts.creditInFinance')}</p>
            </Card>
            <Card title={t('cts.customerConditions')}>
                <TermEditor party={party} profile="customer" terms={terms} current={customer.terms?.payment_term_id ?? null} can={can.assignCustomerTerms} archived={archived} />
            </Card>
        </>
    );
}

function SupplierTab({ party, supplier, terms, can, archived }: { party: Party; supplier: SupplierTab; terms: PaymentTermOption[]; can: ContactCan; archived: boolean }) {
    const { t } = useI18n();

    return (
        <>
            <Card title={t('cts.tab.supplier')}>
                <Facts
                    rows={[
                        [t('common.status'), t(supplier.active ? 'cts.status.active' : 'cts.status.inactive')],
                        [t('cts.supplierCurrency'), supplier.default_currency_code],
                        [t('cts.paymentTerm'), supplier.default_payment_term_days !== null ? `${supplier.default_payment_term_days} ${t('cts.days')}` : null],
                    ]}
                />
                <div className="mt-4">
                    <ButtonLink href={supplier.links.sheet} variant="outline" size="sm">
                        {t('cts.supplierSheet')}
                    </ButtonLink>
                </div>
                <p className="mt-3 text-[13px] text-ink-muted">{t('cts.bankingElsewhere')}</p>
            </Card>
            <Card title={t('cts.supplierConditions')}>
                <TermEditor party={party} profile="supplier" terms={terms} current={supplier.payment_term_id} can={can.assignSupplierTerms} archived={archived} />
            </Card>
        </>
    );
}

function ActivityTab({ activity }: { activity: Activity[] }) {
    const { t } = useI18n();
    if (activity.length === 0) return <Card><p className="text-sm text-ink-muted">{t('cts.noActivity')}</p></Card>;

    return (
        <Card>
            <ul className="divide-y divide-line text-sm">
                {activity.map((entry) => (
                    <li key={entry.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <span className="text-ink">{t(`cts.event.${entry.event}` as never) === `cts.event.${entry.event}` ? entry.event : t(`cts.event.${entry.event}` as never)}</span>
                        <span className="text-[13px] text-ink-muted">
                            {entry.actor ?? '—'} · <bdi>{new Date(entry.created_at).toLocaleString()}</bdi>
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

export type { ContactValues };
