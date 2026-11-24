import { UNAVAILABLE } from '@/components/finance/FinanceClientsKit';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { ButtonLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link } from '@inertiajs/react';

type Customer = { id: number; display_name: string; company_name: string | null; email: string | null; phone: string | null; status: string };
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string };
type Props = { customers: { data: Customer[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number }; filters: Filters };

const FILTER_KEYS = ['search', 'status'] as const;

/**
 * FC-02 — customer accounts index. Customer identity only: a customer's financial position is viewer-scoped
 * (documents of other stores must never move a figure) and has no safe batch query, so it is computed once,
 * on the account page. Placeholders here are an em dash, never a fabricated zero.
 */
export default function FinanceClientsAccounts({ customers, filters }: Props) {
    const { t } = useI18n();
    const f = useQueryFilters('/finance/clients/comptes', filters, { keys: [...FILTER_KEYS] });
    const statusLabel = (status: string) => t(status === 'active' ? 'status.active' : 'status.inactive');
    const statusOptions = ['active', 'inactive'].map((value) => ({ value, label: statusLabel(value) }));
    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: statusLabel(filters.status), onRemove: () => f.remove('status') });

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.accounts.title')} — Finance`} />
            <PageHeader title={t('fc.accounts.title')} description={t('fc.accounts.description')} />

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
                label={t('fc.accounts.title')}
                state={customers.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={t('customers.empty')}
                footer={customers.data.length === 0 ? undefined : <TablePager links={customers.links} from={customers.from} to={customers.to} total={customers.total} />}
            >
                <table className="erp-table min-w-[820px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('customers.name')}</th>
                            <th className="px-4 py-2.5">{t('fc.accounts.contact')}</th>
                            <th className="px-4 py-2.5">{t('common.status')}</th>
                            <th className="num px-4 py-2.5">{t('fc.accounts.position')}</th>
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {customers.data.map((customer) => (
                            <tr key={customer.id}>
                                <td className="max-w-80 px-4 py-3">
                                    <Link href={`/finance/clients/comptes/${customer.id}`} className="block truncate font-semibold text-ink hover:text-accent hover:underline">
                                        {customer.display_name}
                                    </Link>
                                    {customer.company_name && customer.company_name !== customer.display_name && (
                                        <span className="block truncate text-[13px] text-ink-muted">{customer.company_name}</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-ink-muted">
                                    <bdi dir="ltr">{customer.phone ?? customer.email ?? UNAVAILABLE}</bdi>
                                </td>
                                <td className="px-4 py-3">
                                    <DocBadge tone={customer.status === 'active' ? 'positive' : 'neutral'}>{statusLabel(customer.status)}</DocBadge>
                                </td>
                                <td className="num px-4 py-3 text-ink-faint" title={t('fc.accounts.positionHint')}>
                                    <span dir="ltr">{UNAVAILABLE}</span>
                                </td>
                                <td className="px-4 py-3 text-end">
                                    <ButtonLink href={`/finance/clients/comptes/${customer.id}`} size="sm" variant="outline">
                                        {t('fc.accounts.open')}
                                        <span className="sr-only"> {customer.display_name}</span>
                                    </ButtonLink>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>
            <p className="mt-3 text-[13px] text-ink-muted">{t('fc.accounts.positionHint')}</p>
        </ApplicationShell>
    );
}
