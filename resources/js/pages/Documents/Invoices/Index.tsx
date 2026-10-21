import DocBadge from '@/components/ui/DocBadge';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import EmptyState from '@/components/ui/EmptyState';
import { useI18n } from '@/i18n';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatMoney } from '@/utils/format';
import { invoiceStatusLabel, invoiceStatusTone, label } from '@/utils/labels';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Invoice = {
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
type Props = { invoices: { data: Invoice[]; links: PageLink[] }; filters: { search?: string; status?: string; invoice_date?: string } };

export default function InvoiceIndex({ invoices, filters }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState(filters.search ?? '');
    const debounced = useDebouncedValue(search, 250);
    const mounted = useRef(false);

    const apply = (patch: Record<string, string | undefined>) =>
        router.get('/invoices', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;
            return;
        }
        apply({ search: debounced || undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    return (
        <SalesLayout>
            <Head title={t('invoices.title')} />
            <PageHeader title={t('invoices.title')} description={t('invoices.description')} />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchInput
                    value={search}
                    onChange={setSearch}
                    searching={search !== (filters.search ?? '')}
                    placeholder={t('invoices.searchPlaceholder')}
                />
                <FilterSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })}>
                    <option value="">{t('common.allStatuses')}</option>
                    {['draft','issued','cancelled'].map(status => <option key={status} value={status}>{label(invoiceStatusLabel, status)}</option>)}
                </FilterSelect>
                <input
                    type="date"
                    value={filters.invoice_date ?? ''}
                    onChange={(e) => apply({ invoice_date: e.target.value || undefined })}
                    className="control-input"
                />
            </div>

            {invoices.data.length === 0 ? (
                <EmptyState title={t('invoices.empty')} description={t('invoices.description')} />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="erp-table w-full min-w-[760px] text-start text-sm">
                            <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-medium">{t('invoices.invoice')}</th><th className="px-4 py-3 font-medium">{t('common.date')}</th>
                                    <th className="px-4 py-3 font-medium">{t('invoices.order')}</th><th className="px-4 py-3 font-medium">{t('common.customer')}</th>
                                    <th className="px-4 py-3 font-medium">{t('common.status')}</th><th className="px-4 py-3 text-end font-medium">{t('common.total')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {invoices.data.map((invoice) => (
                                    <tr
                                        key={invoice.id}
                                        onClick={() => router.visit(`/invoices/${invoice.id}`)}
                                        className="cursor-pointer transition-soft hover:bg-sage/50"
                                    >
                                        <td className="px-4 py-3 font-medium text-ink">
                                            <bdi>{invoice.invoice_number ?? t('invoices.draft')}</bdi> · V{invoice.version}
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">{formatDate(invoice.invoice_date)}</td>
                                        <td className="px-4 py-3 text-ink-muted">{invoice.sales_order.order_number}</td>
                                        <td className="px-4 py-3 text-ink">
                                            {invoice.customer_company ?? invoice.customer_name ?? t('common.counterCustomer')}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                <DocBadge tone={invoiceStatusTone(invoice.status)}>{label(invoiceStatusLabel, invoice.status)}</DocBadge>
                                                {invoice.credit_state === 'partial' && <DocBadge tone="warning">{t('invoices.partialCredit')}</DocBadge>}
                                                {invoice.credit_state === 'full' && <DocBadge tone="danger">{t('invoices.fullCredit')}</DocBadge>}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-end font-medium tabular-nums text-ink" dir="ltr">
                                            <span className={invoice.credit_state === 'none' ? '' : 'text-ink-muted line-through'}>{formatMoney(invoice.total_incl_tax, invoice.currency_code)}</span>
                                            {invoice.credit_state !== 'none' && <span className="block text-xs font-semibold text-ink">{t('invoices.net')} {formatMoney(invoice.net_total_incl_tax, invoice.currency_code)}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {invoices.data.map((invoice) => (
                            <li key={invoice.id}>
                                <Link href={`/invoices/${invoice.id}`} className="block rounded-card border border-line bg-surface p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-ink"><bdi>{invoice.invoice_number ?? t('invoices.draft')}</bdi> · V{invoice.version}</p>
                                            <p className="text-[13px] text-ink-muted">{formatDate(invoice.invoice_date)}</p>
                                        </div>
                                            <div className="flex flex-wrap gap-1">
                                                <DocBadge tone={invoiceStatusTone(invoice.status)}>{label(invoiceStatusLabel, invoice.status)}</DocBadge>
                                                {invoice.credit_state === 'partial' && <DocBadge tone="warning">{t('invoices.partialCredit')}</DocBadge>}
                                                {invoice.credit_state === 'full' && <DocBadge tone="danger">{t('invoices.fullCredit')}</DocBadge>}
                                            </div>
                                    </div>
                                    <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('common.customer')}</dt>
                                            <dd className="truncate text-ink">{invoice.customer_company ?? invoice.customer_name ?? t('common.counterCustomer')}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('invoices.order')}</dt>
                                            <dd className="truncate text-ink-muted">{invoice.sales_order.order_number}</dd>
                                        </div>
                                    </dl>
                                    <p className="mt-2 text-base font-semibold tabular-nums text-ink">
                                        {formatMoney(invoice.credit_state === 'none' ? invoice.total_incl_tax : invoice.net_total_incl_tax, invoice.currency_code)}
                                    </p>
                                    {invoice.credit_state !== 'none' && <p className="text-xs text-ink-muted">{t('invoices.original')} {formatMoney(invoice.total_incl_tax, invoice.currency_code)} · {t('invoices.credits')} -{formatMoney(invoice.credited_amount, invoice.currency_code)}</p>}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={invoices.links} />
        </SalesLayout>
    );
}
