import SandboxDocumentBadge from '@/components/SandboxDocumentBadge';
import { AdvancedFilterBar, ChoiceFilter, DateFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
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
import { formatDate, formatMoney } from '@/utils/format';
import { invoiceStatusLabel, invoiceStatusTone, label } from '@/utils/labels';
import { Head, Link, usePage } from '@inertiajs/react';

type Invoice = {
    sandbox_id?: number | null;
    id: number;
    invoice_number: string | null;
    version: number;
    invoice_date: string;
    customer_name: string | null;
    customer_company: string | null;
    total_incl_tax: string;
    currency_code: string;
    status: string;
    sales_order: { id: number; order_number: string };
    credited_amount: string;
    net_total_incl_tax: string;
    credit_state: 'none' | 'partial' | 'full';
};
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string; invoice_date?: string };
type Props = { invoices: { data: Invoice[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number }; filters: Filters };

// Exactly the single-value params InvoiceController@index validates.
const FILTER_KEYS = ['search', 'status', 'invoice_date'] as const;

export default function InvoiceIndex({ invoices, filters }: Props) {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const canViewOrders = tenant.permissions.includes('sales_orders.view');
    const f = useQueryFilters('/invoices', filters, { keys: [...FILTER_KEYS] });

    const statusOptions = ['draft', 'issued', 'cancelled'].map((value) => ({ value, label: label(invoiceStatusLabel, value) }));
    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status)
        chips.push({ key: 'status', label: t('common.status'), value: label(invoiceStatusLabel, filters.status), onRemove: () => f.remove('status') });
    if (filters.invoice_date)
        chips.push({ key: 'invoice_date', label: t('common.date'), value: formatDate(filters.invoice_date), onRemove: () => f.remove('invoice_date') });

    const number = (invoice: Invoice) => invoice.invoice_number ?? t('invoices.draft');
    const customer = (invoice: Invoice) => invoice.customer_company ?? invoice.customer_name ?? t('common.counterCustomer');
    const badges = (invoice: Invoice) => (
        <div className="flex flex-wrap gap-1">
            <DocBadge tone={invoiceStatusTone(invoice.status)}>{label(invoiceStatusLabel, invoice.status)}</DocBadge>
            {invoice.credit_state === 'partial' && <DocBadge tone="warning">{t('invoices.partialCredit')}</DocBadge>}
            {invoice.credit_state === 'full' && <DocBadge tone="danger">{t('invoices.fullCredit')}</DocBadge>}
        </div>
    );
    const orderRef = (invoice: Invoice) =>
        canViewOrders ? (
            <Link href={`/sales/orders/${invoice.sales_order.id}`} className="text-ink-muted underline-offset-2 hover:text-accent hover:underline">
                <bdi>{invoice.sales_order.order_number}</bdi>
            </Link>
        ) : (
            <bdi className="text-ink-muted">{invoice.sales_order.order_number}</bdi>
        );

    const filtered = f.activeCount > 0;
    const resetAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : undefined;
    const emptyTitle = filtered ? t('filters.noMatch') : t('invoices.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('invoices.description');

    return (
        <SalesLayout>
            <Head title={t('invoices.title')} />
            <PageHeader title={t('invoices.title')} description={t('invoices.description')} />

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('invoices.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('common.status')} options={statusOptions} value={filters.status} onChange={(value) => f.set('status', value)} />
                    <DateFilter label={t('common.date')} value={filters.invoice_date} onChange={(value) => f.set('invoice_date', value)} />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('invoices.title')}
                state={invoices.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={resetAction}
                className="hidden xl:block"
                footer={invoices.data.length === 0 ? undefined : <TablePager links={invoices.links} from={invoices.from} to={invoices.to} total={invoices.total} />}
            >
                <table className="erp-table min-w-[860px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('invoices.invoice')}</th>
                            <th className="px-4 py-2.5">{t('common.date')}</th>
                            <th className="px-4 py-2.5">{t('invoices.order')}</th>
                            <th className="px-4 py-2.5">{t('common.customer')}</th>
                            <th className="px-4 py-2.5">{t('common.status')}</th>
                            <th className="num px-4 py-2.5">{t('common.total')}</th>
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoices.data.map((invoice) => {
                            const row = rowLink(`/invoices/${invoice.id}`);
                            return (
                                <tr key={invoice.id} onClick={row.onClick} className={row.className}>
                                    <td className="px-4 py-3">
                                        <span className="inline-flex flex-wrap items-center gap-1.5">
                                            <Link href={`/invoices/${invoice.id}`} className="font-semibold text-ink hover:text-accent hover:underline">
                                                <bdi>{number(invoice)}</bdi>
                                            </Link>
                                            <span className="text-[13px] text-ink-muted">V{invoice.version}</span>
                                            <SandboxDocumentBadge sandboxId={invoice.sandbox_id} />
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{formatDate(invoice.invoice_date)}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">{orderRef(invoice)}</td>
                                    <td className="max-w-72 truncate px-4 py-3 font-medium text-ink">{customer(invoice)}</td>
                                    <td className="px-4 py-3">{badges(invoice)}</td>
                                    <td className="num px-4 py-3" dir="ltr">
                                        <span className={invoice.credit_state === 'none' ? 'font-semibold text-ink' : 'text-ink-muted line-through'}>
                                            {formatMoney(invoice.total_incl_tax, invoice.currency_code)}
                                        </span>
                                        {invoice.credit_state !== 'none' && (
                                            <span className="block text-[13px] font-semibold text-ink">
                                                {t('invoices.net')} {formatMoney(invoice.net_total_incl_tax, invoice.currency_code)}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        <ButtonLink href={`/invoices/${invoice.id}`} size="sm" variant="outline">
                                            {t('common.open')}
                                            <span className="sr-only"> {number(invoice)}</span>
                                        </ButtonLink>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards (the filter bar above is shared with desktop) */}
            <div className="xl:hidden" aria-busy={f.loading || undefined}>
                {invoices.data.length === 0 ? (
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={resetAction} />
                ) : (
                    <ul className={`grid gap-3 sm:grid-cols-2 lg:grid-cols-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {invoices.data.map((invoice) => (
                            <li key={invoice.id} className="surface-card flex flex-col p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/invoices/${invoice.id}`} className="block truncate text-base font-semibold text-ink hover:underline">
                                            <bdi>{number(invoice)}</bdi> <span className="text-[13px] font-normal text-ink-muted">V{invoice.version}</span>
                                        </Link>
                                        <p className="text-[13px] text-ink-muted">{formatDate(invoice.invoice_date)}</p>
                                    </div>
                                    <p className="metric-value shrink-0 text-end text-base font-semibold text-ink">
                                        {formatMoney(invoice.credit_state === 'none' ? invoice.total_incl_tax : invoice.net_total_incl_tax, invoice.currency_code)}
                                    </p>
                                </div>
                                <p className="mt-2 truncate text-sm font-medium text-ink">{customer(invoice)}</p>
                                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                    {badges(invoice)}
                                    <SandboxDocumentBadge sandboxId={invoice.sandbox_id} />
                                </div>
                                <p className="mt-2 text-[13px] text-ink-muted">
                                    {t('invoices.order')} : {orderRef(invoice)}
                                </p>
                                {invoice.credit_state !== 'none' && (
                                    <p className="mt-1 text-[13px] text-ink-muted">
                                        {t('invoices.original')} {formatMoney(invoice.total_incl_tax, invoice.currency_code)} · {t('invoices.credits')} -
                                        {formatMoney(invoice.credited_amount, invoice.currency_code)}
                                    </p>
                                )}
                                <ButtonLink href={`/invoices/${invoice.id}`} size="sm" variant="outline" className="mt-3 w-full">
                                    {t('common.open')}
                                    <span className="sr-only"> {number(invoice)}</span>
                                </ButtonLink>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-4">
                    <TablePager links={invoices.links} from={invoices.from} to={invoices.to} total={invoices.total} />
                </div>
            </div>
        </SalesLayout>
    );
}
