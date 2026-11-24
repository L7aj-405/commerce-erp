import { UNAVAILABLE } from '@/components/finance/FinanceClientsKit';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Alert } from '@/components/ui/Alert';
import { ButtonLink, DownloadLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
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
const SORTS = ['name', 'receivable', 'overdue', 'unbilled', 'net_invoiced', 'advances_available', 'available_credit'] as const;
const STATUS_TONE: Record<string, 'positive' | 'neutral' | 'warning' | 'danger'> = { normal: 'positive', watch: 'warning', overdue: 'warning', over_limit: 'danger', blocked: 'danger' };

/**
 * FC-02 — financial customer index. Every figure comes from CustomerFinancialPositionService (one bulk computation for the
 * page, viewer-scoped; an em dash means "not visible with your permissions or stores" or "several currencies"). Sorting and
 * filtering by a financial figure are server-side over the whole filtered customer set, never over the visible rows.
 */
export default function FinanceClientsAccounts({ customers, filters, can, financialSortLimited }: Props) {
    const { t } = useI18n();
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors;
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

            <TableShell
                label={t('fc.accounts.title')}
                state={customers.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={t('customers.empty')}
                footer={customers.data.length === 0 ? undefined : <TablePager links={customers.links} from={customers.from} to={customers.to} total={customers.total} />}
            >
                <table className="erp-table min-w-[1180px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('customers.name')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.col.netInvoiced')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.col.receivable')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.col.unbilled')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.col.advances')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.col.refundObligation')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.col.instruments')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.col.credit')}</th>
                            <th className="px-4 py-2.5">{t('fc2.col.financialStatus')}</th>
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {customers.data.map((customer) => {
                            const p = customer.position;
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
                                    <td className="num px-4 py-3" dir="ltr">{money(p.net_invoiced, p.currency_code)}</td>
                                    <td className="num px-4 py-3" dir="ltr">
                                        {money(p.receivable, p.currency_code)}
                                        {p.overdue_invoices !== null && Number(p.overdue_invoices) > 0 && <span className="block text-[12px] text-danger">{t('fc2.col.overdue')} {formatMoney(p.overdue_invoices, p.currency_code ?? 'MAD')}</span>}
                                    </td>
                                    <td className="num px-4 py-3" dir="ltr">
                                        {money(p.unbilled, p.currency_code)}
                                        {p.overdue_unbilled !== null && Number(p.overdue_unbilled) > 0 && <span className="block text-[12px] text-danger">{t('fc2.col.overdue')} {formatMoney(p.overdue_unbilled, p.currency_code ?? 'MAD')}</span>}
                                    </td>
                                    <td className="num px-4 py-3" dir="ltr">{money(p.advances_available, p.currency_code)}</td>
                                    <td className="num px-4 py-3" dir="ltr">{money(p.refund_obligation, p.currency_code)}</td>
                                    <td className="num px-4 py-3" dir="ltr">{money(p.pending_instruments, p.currency_code)}</td>
                                    <td className="num px-4 py-3" dir="ltr">
                                        {p.credit_limit === null ? UNAVAILABLE : money(p.credit_limit, p.currency_code)}
                                        {p.available_credit !== null && <span className="block text-[12px] text-ink-muted">{t('fc2.col.available')} {formatMoney(p.available_credit, p.currency_code ?? 'MAD')}</span>}
                                    </td>
                                    <td className="px-4 py-3">
                                        <DocBadge tone={STATUS_TONE[p.financial_status] ?? 'neutral'}>{fin(p.financial_status)}</DocBadge>
                                    </td>
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
        </ApplicationShell>
    );
}
