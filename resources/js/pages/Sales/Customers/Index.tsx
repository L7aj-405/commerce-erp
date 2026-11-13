import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { rowLink, TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import type { SharedPageProps } from '@/types/app';
import { Head, Link, usePage } from '@inertiajs/react';

type Customer = { id: number; display_name: string; company_name: string | null; email: string | null; phone: string | null; type: string; status: string };
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string };
type Props = { customers: { data: Customer[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number }; filters: Filters };

// Exactly the params CustomerController@index validates.
const FILTER_KEYS = ['search', 'status'] as const;

export default function CustomerIndex({ customers, filters }: Props) {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const canCreate = tenant.permissions.includes('customers.create');
    const f = useQueryFilters('/sales/customers', filters, { keys: [...FILTER_KEYS] });

    const statusLabel = (status: string) => t(status === 'active' ? 'status.active' : 'status.inactive');
    const statusOptions = ['active', 'inactive'].map((value) => ({ value, label: statusLabel(value) }));
    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: statusLabel(filters.status), onRemove: () => f.remove('status') });

    const typeLabel = (customer: Customer) => (customer.type === 'company' ? t('customers.business') : t('customers.individual'));
    const badge = (customer: Customer) => <DocBadge tone={customer.status === 'active' ? 'positive' : 'neutral'}>{statusLabel(customer.status)}</DocBadge>;

    const filtered = f.activeCount > 0;
    const createAction = canCreate ? <ButtonLink href="/sales/customers/create">+ {t('customers.new')}</ButtonLink> : undefined;
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : (
        createAction
    );
    const emptyTitle = filtered ? t('filters.noMatch') : t('customers.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('customers.emptyHelp');
    const pager = <TablePager links={customers.links} from={customers.from} to={customers.to} total={customers.total} />;

    return (
        <SalesLayout>
            <Head title={t('customers.title')} />
            <PageHeader title={t('customers.title')} description={t('customers.description')} actions={createAction} />

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('customers.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('common.status')} options={statusOptions} value={filters.status} onChange={(value) => f.set('status', value)} />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('customers.title')}
                state={customers.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden lg:block"
                footer={customers.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[820px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('customers.name')}</th>
                            <th className="px-4 py-2.5">{t('customers.email')}</th>
                            <th className="px-4 py-2.5">{t('customers.phone')}</th>
                            <th className="px-4 py-2.5">{t('customers.type')}</th>
                            <th className="px-4 py-2.5">{t('common.status')}</th>
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {customers.data.map((customer) => {
                            const row = rowLink(`/sales/customers/${customer.id}`);
                            return (
                                <tr key={customer.id} onClick={row.onClick} className={row.className}>
                                    <td className="max-w-80 px-4 py-3">
                                        <Link href={`/sales/customers/${customer.id}`} className="block truncate font-semibold text-ink hover:text-accent hover:underline">
                                            {customer.display_name}
                                        </Link>
                                        {customer.company_name && customer.company_name !== customer.display_name && (
                                            <span className="block truncate text-[13px] text-ink-muted">{customer.company_name}</span>
                                        )}
                                    </td>
                                    <td className="max-w-64 truncate px-4 py-3 text-ink-muted">
                                        <bdi dir="ltr">{customer.email ?? '—'}</bdi>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">
                                        <bdi dir="ltr">{customer.phone ?? '—'}</bdi>
                                    </td>
                                    <td className="px-4 py-3 text-ink-muted">{typeLabel(customer)}</td>
                                    <td className="px-4 py-3">{badge(customer)}</td>
                                    <td className="px-4 py-3 text-end">
                                        <ButtonLink href={`/sales/customers/${customer.id}`} size="sm" variant="outline">
                                            {t('common.open')}
                                            <span className="sr-only"> {customer.display_name}</span>
                                        </ButtonLink>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile/tablet: stacked cards (filter bar above is shared). */}
            <div className="lg:hidden" aria-busy={f.loading || undefined}>
                {customers.data.length === 0 ? (
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                ) : (
                    <ul className={`grid gap-3 transition-opacity sm:grid-cols-2 ${f.loading ? 'opacity-55' : ''}`}>
                        {customers.data.map((customer) => (
                            <li key={customer.id} className="surface-card flex flex-col p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/sales/customers/${customer.id}`} className="block truncate text-base font-semibold text-ink hover:underline">
                                            {customer.display_name}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">
                                            {typeLabel(customer)}
                                            {customer.company_name && customer.company_name !== customer.display_name ? ` · ${customer.company_name}` : ''}
                                        </p>
                                    </div>
                                    {badge(customer)}
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('customers.email')}</dt>
                                        <dd className="truncate text-ink">
                                            <bdi dir="ltr">{customer.email ?? '—'}</bdi>
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('customers.phone')}</dt>
                                        <dd className="truncate text-ink tabular-nums">
                                            <bdi dir="ltr">{customer.phone ?? '—'}</bdi>
                                        </dd>
                                    </div>
                                </dl>
                                <ButtonLink href={`/sales/customers/${customer.id}`} size="sm" variant="outline" className="mt-3 w-full">
                                    {t('common.open')}
                                    <span className="sr-only"> {customer.display_name}</span>
                                </ButtonLink>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-4">{pager}</div>
            </div>
        </SalesLayout>
    );
}
