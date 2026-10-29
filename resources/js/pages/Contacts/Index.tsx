import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SalesLayout from '@/layouts/SalesLayout';
import { useI18n } from '@/i18n';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Contact = {
    id: number;
    full_name: string;
    company_name: string | null;
    job_title: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    whatsapp_username: string | null;
    contact_type: string;
    active: boolean;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    contacts: { data: Contact[]; links: LinkData[] };
    filters: { search?: string; contact_type?: string; active?: string };
    can: { create: boolean; update: boolean; archive: boolean };
};

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';
export default function ContactsIndex({ contacts, filters, can }: Props) {
    const { t } = useI18n();
    const typeLabels: Record<string, string> = { client: t('contacts.client'), supplier: t('contacts.supplier'), internal: t('contacts.internal'), other: t('contacts.other') };
    const [search, setSearch] = useState(filters.search ?? '');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/contacts', { ...filters, search: search || undefined, page: undefined }, { preserveState: true, replace: true });
    };

    return (
        <SalesLayout>
            <Head title={t('contacts.title')} />
            <PageHeader
                title={t('contacts.title')}
                description={t('contacts.description')}
                actions={can.create ? <Link href="/contacts/create" className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg transition-soft hover:bg-primary-hover">+ {t('contacts.new')}</Link> : undefined}
            />

            <form onSubmit={submit} className="mb-6 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('contacts.searchPlaceholder')} className={`${fieldClass} w-full min-w-0 flex-1 sm:min-w-72 sm:w-auto`} />
                <select value={filters.contact_type ?? ''} onChange={(e) => router.get('/contacts', { ...filters, contact_type: e.target.value || undefined, page: undefined }, { preserveState: true })} className={`${fieldClass} flex-1 sm:flex-none`}>
                    <option value="">{t('contacts.allTypes')}</option>
                    <option value="client">{t('contacts.client')}</option><option value="supplier">{t('contacts.supplier')}</option><option value="internal">{t('contacts.internal')}</option><option value="transport">{t('contacts.transport')}</option><option value="other">{t('contacts.other')}</option>
                </select>
                <select value={filters.active ?? ''} onChange={(e) => router.get('/contacts', { ...filters, active: e.target.value || undefined, page: undefined }, { preserveState: true })} className={`${fieldClass} flex-1 sm:flex-none`}>
                    <option value="">{t('common.allStatuses')}</option><option value="1">{t('contacts.activePlural')}</option><option value="0">{t('contacts.archivedPlural')}</option>
                </select>
                <button type="submit" className="inline-flex min-h-10 flex-1 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised sm:flex-none">{t('common.search')}</button>
            </form>

            {contacts.data.length === 0 ? (
                <EmptyState title={t('contacts.empty')} description={t('contacts.emptyHelp')} actions={can.create ? <Link href="/contacts/create" className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg">+ {t('contacts.new')}</Link> : undefined} />
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="erp-table w-full min-w-[850px] text-start text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-2.5">{t('contacts.contact')}</th><th className="px-4 py-2.5">{t('contacts.company')}</th><th className="px-4 py-2.5">{t('contacts.type')}</th><th className="px-4 py-2.5">{t('contacts.email')}</th><th className="px-4 py-2.5">{t('contacts.phone')}</th><th className="px-4 py-2.5">{t('common.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {contacts.data.map((contact) => (
                                    <tr key={contact.id} className="border-t border-line">
                                        <td className="px-4 py-2.5">
                                            <Link href={`/contacts/${contact.id}`} className="font-medium text-ink">{contact.full_name}</Link>
                                            {contact.job_title && <p className="text-xs text-ink-muted">{contact.job_title}</p>}
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{contact.company_name ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{typeLabels[contact.contact_type] ?? contact.contact_type}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{contact.email ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{contact.phone ?? contact.whatsapp ?? (contact.whatsapp_username ? `@${contact.whatsapp_username}` : '—')}</td>
                                        <td className="px-4 py-2.5"><DocBadge tone={contact.active ? 'positive' : 'neutral'}>{contact.active ? t('contacts.active') : t('contacts.archived')}</DocBadge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <ul className="space-y-3 md:hidden">
                        {contacts.data.map((contact) => (
                            <li key={contact.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/contacts/${contact.id}`} className="block truncate text-sm font-semibold text-ink">{contact.full_name}</Link>
                                        <p className="truncate text-[13px] text-ink-muted">{contact.company_name ?? contact.email ?? '—'}</p>
                                    </div>
                                    <DocBadge tone={contact.active ? 'positive' : 'neutral'}>{contact.active ? t('contacts.active') : t('contacts.archived')}</DocBadge>
                                </div>
                                <div className="mt-3 text-[13px] text-ink-muted">
                                    <p>{typeLabels[contact.contact_type] ?? contact.contact_type}</p>
                                    <p>{contact.phone ?? contact.whatsapp ?? (contact.whatsapp_username ? `@${contact.whatsapp_username}` : '—')}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={contacts.links} />
        </SalesLayout>
    );
}
