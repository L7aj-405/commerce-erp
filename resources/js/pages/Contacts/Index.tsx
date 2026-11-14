import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { Badge } from '@/components/ui/StatusBadge';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link } from '@inertiajs/react';

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
    contacts: { data: Contact[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: { search?: string; contact_type?: string; active?: string };
    can: { create: boolean; update: boolean; archive: boolean };
};

export default function ContactsIndex({ contacts, filters, can }: Props) {
    const { t } = useI18n();
    // Exactly the params OrganizationContactController@index validates.
    const f = useQueryFilters('/contacts', filters, { keys: ['search', 'contact_type', 'active'] });
    const typeOptions = [
        { value: 'client', label: t('contacts.client') },
        { value: 'supplier', label: t('contacts.supplier') },
        { value: 'internal', label: t('contacts.internal') },
        { value: 'transport', label: t('contacts.transport') },
        { value: 'other', label: t('contacts.other') },
    ];
    const statusOptions = [
        { value: '1', label: t('contacts.activePlural') },
        { value: '0', label: t('contacts.archivedPlural') },
    ];
    const typeLabel = (type: string) => typeOptions.find((option) => option.value === type)?.label ?? type;
    const reach = (contact: Contact) => contact.phone ?? contact.whatsapp ?? (contact.whatsapp_username ? `@${contact.whatsapp_username}` : null);

    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.contact_type) chips.push({ key: 'contact_type', label: t('contacts.type'), value: typeLabel(filters.contact_type), onRemove: () => f.remove('contact_type') });
    if (filters.active !== undefined && filters.active !== null && filters.active !== '') {
        chips.push({ key: 'active', label: t('common.status'), value: statusOptions.find((o) => o.value === String(filters.active))?.label ?? String(filters.active), onRemove: () => f.remove('active') });
    }
    const filtered = chips.length > 0;

    const createAction = can.create ? <ButtonLink href="/contacts/create">+ {t('contacts.new')}</ButtonLink> : undefined;
    const emptyTitle = filtered ? t('filters.noMatch') : t('contacts.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('contacts.emptyHelp');
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : (
        createAction
    );
    const pager = <TablePager links={contacts.links} from={contacts.from} to={contacts.to} total={contacts.total} />;
    const statusBadge = (contact: Contact) => <Badge variant={contact.active ? 'success' : 'neutral'}>{contact.active ? t('contacts.active') : t('contacts.archived')}</Badge>;

    return (
        <ApplicationShell>
            <Head title={t('contacts.title')} />
            <PageHeader title={t('contacts.title')} description={t('contacts.description')} actions={createAction} />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('contacts.title')}
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('contacts.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={chips.length}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('contacts.type')} allLabel={t('contacts.allTypes')} options={typeOptions} value={filters.contact_type} onChange={(value) => f.set('contact_type', value)} />
                    <ChoiceFilter label={t('common.status')} allLabel={t('common.allStatuses')} options={statusOptions} value={filters.active} onChange={(value) => f.set('active', value)} />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('contacts.title')}
                state={contacts.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden md:block"
                footer={contacts.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[850px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t('contacts.contact')}</th>
                            <th className="px-4 py-3">{t('contacts.company')}</th>
                            <th className="px-4 py-3">{t('contacts.type')}</th>
                            <th className="px-4 py-3">{t('contacts.email')}</th>
                            <th className="px-4 py-3">{t('contacts.phone')}</th>
                            <th className="px-4 py-3">{t('common.status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {contacts.data.map((contact) => (
                            <tr key={contact.id}>
                                <td className="px-4 py-3">
                                    <Link href={`/contacts/${contact.id}`} className="font-medium text-ink underline-offset-2 hover:underline">
                                        {contact.full_name}
                                    </Link>
                                    {contact.job_title && <p className="text-xs text-ink-muted">{contact.job_title}</p>}
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{contact.company_name ?? '—'}</td>
                                <td className="px-4 py-3 text-ink-muted">{typeLabel(contact.contact_type)}</td>
                                <td className="px-4 py-3 text-ink-muted">{contact.email ? <bdi>{contact.email}</bdi> : '—'}</td>
                                <td className="px-4 py-3 text-ink-muted">{reach(contact) ? <bdi dir="ltr">{reach(contact)}</bdi> : '—'}</td>
                                <td className="px-4 py-3">{statusBadge(contact)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards */}
            {contacts.data.length === 0 ? (
                <div className="md:hidden">
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                </div>
            ) : (
                <div className="space-y-3 md:hidden" aria-busy={f.loading || undefined}>
                    <ul className={`space-y-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {contacts.data.map((contact) => (
                            <li key={contact.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/contacts/${contact.id}`} className="block truncate text-sm font-semibold text-ink">
                                            {contact.full_name}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">{contact.company_name ?? contact.email ?? '—'}</p>
                                    </div>
                                    <span className="shrink-0">{statusBadge(contact)}</span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('contacts.type')}</dt>
                                        <dd className="truncate text-ink-muted">{typeLabel(contact.contact_type)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('contacts.phone')}</dt>
                                        <dd className="truncate text-ink-muted">{reach(contact) ? <bdi dir="ltr">{reach(contact)}</bdi> : '—'}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                    {pager}
                </div>
            )}
        </ApplicationShell>
    );
}
