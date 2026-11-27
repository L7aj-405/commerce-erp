import { UNAVAILABLE } from '@/components/finance/FinanceClientsKit';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Alert } from '@/components/ui/Alert';
import { ColumnVisibilityMenu } from '@/components/ui/ColumnVisibilityMenu';
import { ButtonLink, DownloadLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useColumnVisibility } from '@/hooks/useColumnVisibility';
import type { ColumnDef } from '@/hooks/useColumnVisibility';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link, usePage } from '@inertiajs/react';

type Position = {
    currency_code: string | null;
    mixed_currency: boolean;
    net_invoiced: string | null;
    paid: string | null;
    receivable: string | null;
    overdue_invoices: string | null;
    unbilled: string | null;
    overdue_unbilled: string | null;
    overdue: string | null;
    total_to_collect: string | null;
    opening_balance: string | null;
    advances_available: string | null;
    refund_obligation: string | null;
    pending_instruments: string | null;
    credit_limit: string | null;
    available_credit: string | null;
    financial_status: 'normal' | 'watch' | 'overdue' | 'over_limit' | 'blocked';
};
type Customer = { id: number; display_name: string; company_name: string | null; type: string; email: string | null; phone: string | null; status: string; position: Position };
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string; financial_status?: string; sort?: string; dir?: string };
type Props = {
    customers: { data: Customer[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number };
    filters: Filters;
    can: { export: boolean };
    financialSortLimited: boolean;
};

const FILTER_KEYS = ['search', 'status', 'financial_status', 'sort', 'dir'] as const;
const STATUSES = ['normal', 'watch', 'overdue', 'over_limit', 'blocked'] as const;
const SORTS = ['name', 'total_to_collect', 'receivable', 'overdue', 'unbilled', 'net_invoiced', 'advances_available', 'available_credit'] as const;
const STATUS_TONE: Record<string, 'positive' | 'neutral' | 'warning' | 'danger'> = { normal: 'positive', watch: 'warning', overdue: 'warning', over_limit: 'danger', blocked: 'danger' };

/**
 * FC-02 — financial customer index. Every figure comes from CustomerFinancialPositionService (one bulk computation for the
 * page, viewer-scoped; an em dash means "not visible with your permissions or stores" or "several currencies"). Sorting and
 * filtering by a financial figure are server-side over the whole filtered customer set, never over the visible rows.
 */
export default function FinanceClientsAccounts({ customers, filters, can, financialSortLimited }: Props) {
    const { t } = useI18n();
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors;
    const columns: ColumnDef[] = [
        { key: 'client', label: t('customers.name'), defaultVisible: true, locked: true },
        { key: 'total', label: t('fc2.col.totalToCollect'), defaultVisible: true },
        { key: 'overdue', label: t('fc2.col.overdueTotal'), defaultVisible: true },
        { key: 'advances', label: t('fc2.col.advancesShort'), defaultVisible: true },
        { key: 'refund', label: t('fc2.col.toRefund'), defaultVisible: true },
        { key: 'status', label: t('fc2.col.financialStatus'), defaultVisible: true },
        { key: 'netInvoiced', label: t('fc2.col.netInvoiced'), defaultVisible: false },
        { key: 'receivable', label: t('fc2.col.receivable'), defaultVisible: false },
        { key: 'unbilled', label: t('fc2.col.unbilled'), defaultVisible: false },
        { key: 'instruments', label: t('fc2.col.instruments'), defaultVisible: false },
        { key: 'credit', label: t('fc2.col.credit'), defaultVisible: false },
        { key: 'actions', label: t('common.actions'), defaultVisible: true, locked: true },
    ];
    const cols = useColumnVisibility('finance.clients.accounts', columns);
    const show = cols.isVisible;
    const f = useQueryFilters('/finance/clients/comptes', filters, { keys: [...FILTER_KEYS] });
    const statusLabel = (status: string) => t(status === 'active' ? 'status.active' : 'status.inactive');
    const fin = (status: string) => t(`fc2.status.${status}` as TranslationKey);
    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: statusLabel(filters.status), onRemove: () => f.remove('status') });
    if (filters.financial_status) chips.push({ key: 'financial_status', label: t('fc2.col.financialStatus'), value: fin(filters.financial_status), onRemove: () => f.remove('financial_status') });
    const query = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
    const money = (value: string | null, currency: string | null) => (value === null ? UNAVAILABLE : formatMoney(value, currency ?? 'MAD'));

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.accounts.title')} — Finance`} />
            <PageHeader
                title={t('fc.accounts.title')}
                description={t('fc2.accounts.description')}
                actions={
                    can.export ? (
                        <div className="flex gap-2">
                            <DownloadLink href={`/finance/clients/comptes/export/csv?${query}`} variant="secondary" size="sm">
                                {t('accountMovements.exportCsv')}
                            </DownloadLink>
                            <DownloadLink href={`/finance/clients/comptes/export/xlsx?${query}`} variant="secondary" size="sm">
                                {t('accountMovements.exportXlsx')}
                            </DownloadLink>
                        </div>
                    ) : undefined
                }
            />
            {errors?.export && (
                <div className="mb-4">
                    <Alert tone="danger">{errors.export}</Alert>
                </div>
            )}
            {financialSortLimited && (
                <div className="mb-4">
                    <Alert tone="warning">{t('fc2.accounts.tooMany')}</Alert>
                </div>
            )}

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('customers.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('common.status')} options={['active', 'inactive'].map((v) => ({ value: v, label: statusLabel(v) }))} value={filters.status} onChange={(v) => f.set('status', v)} />
                    <ChoiceFilter label={t('fc2.col.financialStatus')} options={STATUSES.map((v) => ({ value: v, label: fin(v) }))} value={filters.financial_status} onChange={(v) => f.set('financial_status', v)} />
                    <ChoiceFilter label={t('fc2.sort')} options={SORTS.map((v) => ({ value: v, label: t(`fc2.sort.${v}` as TranslationKey) }))} value={filters.sort} onChange={(v) => f.set('sort', v)} />
                    <ChoiceFilter label={t('fc2.sort.dir')} options={[{ value: 'asc', label: '↑' }, { value: 'desc', label: '↓' }]} value={filters.dir} onChange={(v) => f.set('dir', v)} />
                </AdvancedFilterBar>
            </div>

            <div className="mb-2 flex justify-end">
                <ColumnVisibilityMenu columns={columns} isVisible={show} onToggle={cols.toggle} onReset={cols.reset} isDefault={cols.isDefault} />
            </div>
            <TableShell
                label={t('fc.accounts.title')}
                state={customers.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={t('customers.empty')}
                footer={customers.data.length === 0 ? undefined : <TablePager links={customers.links} from={customers.from} to={customers.to} total={customers.total} />}
            >
                <table className="erp-table text-sm" style={{ minWidth: `${420 + 150 * columns.filter((c) => show(c.key) && !c.locked).length}px` }}>
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('customers.name')}</th>
                            {show('total') && <th className="num px-4 py-2.5">{t('fc2.col.totalToCollect')}</th>}
                            {show('overdue') && <th className="num px-4 py-2.5">{t('fc2.col.overdueTotal')}</th>}
                            {show('advances') && <th className="num px-4 py-2.5">{t('fc2.col.advancesShort')}</th>}
                            {show('refund') && <th className="num px-4 py-2.5">{t('fc2.col.toRefund')}</th>}
                            {show('status') && <th className="px-4 py-2.5">{t('fc2.col.financialStatus')}</th>}
                            {show('netInvoiced') && <th className="num px-4 py-2.5">{t('fc2.col.netInvoiced')}</th>}
                            {show('receivable') && <th className="num px-4 py-2.5">{t('fc2.col.receivable')}</th>}
                            {show('unbilled') && <th className="num px-4 py-2.5">{t('fc2.col.unbilled')}</th>}
                            {show('instruments') && <th className="num px-4 py-2.5">{t('fc2.col.instruments')}</th>}
                            {show('credit') && <th className="num px-4 py-2.5">{t('fc2.col.credit')}</th>}
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {customers.data.map((customer) => {
                            const p = customer.position;
                            const cur = p.currency_code ?? 'MAD';
                            return (
                                <tr key={customer.id}>
                                    <td className="max-w-72 px-4 py-3">
                                        <Link href={`/finance/clients/comptes/${customer.id}`} className="block truncate font-semibold text-ink hover:text-accent hover:underline">
                                            {customer.display_name}
                                        </Link>
                                        <span className="block truncate text-[13px] text-ink-muted">
                                            {[customer.company_name && customer.company_name !== customer.display_name ? customer.company_name : null, t(customer.type === 'company' ? 'fc2.type.company' : 'fc2.type.individual'), customer.status === 'active' ? null : statusLabel(customer.status)]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </td>
                                    {show('total') && <td className="num px-4 py-3 font-semibold" dir="ltr">{money(p.total_to_collect, p.currency_code)}</td>}
                                    {show('overdue') && (
                                        <td className={`num px-4 py-3 ${p.overdue !== null && Number(p.overdue) > 0 ? 'text-danger' : ''}`} dir="ltr">
                                            {money(p.overdue, p.currency_code)}
                                        </td>
                                    )}
                                    {show('advances') && <td className="num px-4 py-3" dir="ltr">{money(p.advances_available, p.currency_code)}</td>}
                                    {show('refund') && <td className="num px-4 py-3" dir="ltr">{money(p.refund_obligation, p.currency_code)}</td>}
                                    {show('status') && (
                                        <td className="px-4 py-3">
                                            <DocBadge tone={STATUS_TONE[p.financial_status] ?? 'neutral'}>{fin(p.financial_status)}</DocBadge>
                                        </td>
                                    )}
                                    {show('netInvoiced') && <td className="num px-4 py-3" dir="ltr">{money(p.net_invoiced, p.currency_code)}</td>}
                                    {show('receivable') && <td className="num px-4 py-3" dir="ltr">{money(p.receivable, p.currency_code)}</td>}
                                    {show('unbilled') && <td className="num px-4 py-3" dir="ltr">{money(p.unbilled, p.currency_code)}</td>}
                                    {show('instruments') && <td className="num px-4 py-3" dir="ltr">{money(p.pending_instruments, p.currency_code)}</td>}
                                    {show('credit') && (
                                        <td className="num px-4 py-3" dir="ltr">
                                            {p.credit_limit === null ? UNAVAILABLE : money(p.credit_limit, p.currency_code)}
                                            {p.available_credit !== null && <span className="block text-[12px] text-ink-muted">{t('fc2.col.available')} {formatMoney(p.available_credit, cur)}</span>}
                                        </td>
                                    )}
                                    <td className="px-4 py-3 text-end">
                                        <ButtonLink href={`/finance/clients/comptes/${customer.id}`} size="sm" variant="outline">
                                            {t('fc.accounts.open')}
                                            <span className="sr-only"> {customer.display_name}</span>
                                        </ButtonLink>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>
            <p className="mt-3 text-[13px] text-ink-muted">{t('fc2.accounts.legend')}</p>
            <p className="mt-1 text-[13px] text-ink-muted">{t('fc2.accounts.legend2')}</p>
        </ApplicationShell>
    );
}
