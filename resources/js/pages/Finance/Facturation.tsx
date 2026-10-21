import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type InvoiceRow = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    customer_name: string | null;
    customer_company: string | null;
    total_incl_tax: string;
    credited_amount: string;
    net_total_incl_tax: string;
    paid_amount: string;
    outstanding: string;
    fully_paid_at: string | null;
    status: 'unpaid' | 'partial' | 'paid';
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    invoices: { data: InvoiceRow[]; links: LinkData[]; total: number };
};

export default function FinanceFacturation({ organization, period, periodLabel, storeId, stores, invoices }: Props) {
    const { t } = useI18n();
    const statusLabel = (status: InvoiceRow['status']) => status === 'paid' ? t('finance.billing.settled') : status === 'partial' ? t('finance.partiallyPaid') : t('finance.unpaid');
    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.billing.title')} — Finance`} />
            <PageHeader
                title={t('finance.billing.title')}
                description={`${organization.name} · ${periodLabel} · ${invoices.total} ${t('finance.billing.issuedCount')} — ${t('finance.billing.currentDocumentHelp')}`}
            />
            <FinanceFilters path="/finance/facturation" period={period} storeId={storeId} stores={stores} />

            {invoices.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center text-sm text-ink-faint">
                    {t('finance.billing.empty')}
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="erp-table">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('finance.billing.invoiceNumber')}</th>
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.billing.grossInvoice')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.billing.creditNotes')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.billing.netBilled')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.paid')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.billing.balance')}</th>
                                    <th className="px-4 py-2.5">{t('common.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.data.map((invoice) => (
                                    <tr key={invoice.id} className="border-t border-line">
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">
                                            <Link href={`/invoices/${invoice.id}`}>{invoice.invoice_number}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{invoice.invoice_date}</td>
                                        <td className="px-4 py-2.5">{invoice.customer_company || invoice.customer_name || '—'}</td>
                                        <td className="metric-value px-4 py-2.5 text-end" dir="ltr">{formatMoney(invoice.total_incl_tax)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end text-danger" dir="ltr">-{formatMoney(invoice.credited_amount)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end font-medium" dir="ltr">{formatMoney(invoice.net_total_incl_tax)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end text-ink-muted" dir="ltr">{formatMoney(invoice.paid_amount)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end" dir="ltr">{formatMoney(invoice.outstanding)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">
                                            <StatusBadge status={invoice.status}>{statusLabel(invoice.status)}</StatusBadge>
                                            {invoice.status === 'paid' && invoice.fully_paid_at && (
                                                <span className="ms-1 text-[11px] text-ink-faint">({invoice.fully_paid_at})</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {invoices.data.map((invoice) => (
                            <li key={invoice.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/invoices/${invoice.id}`} className="block truncate text-sm font-semibold text-ink">
                                            {invoice.invoice_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">{invoice.customer_company || invoice.customer_name || '—'}</p>
                                    </div>
                                    <StatusBadge status={invoice.status}>{statusLabel(invoice.status)}</StatusBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('common.date')}</dt>
                                        <dd className="text-ink-muted">
                                            {invoice.invoice_date}
                                            {invoice.status === 'paid' && invoice.fully_paid_at && (
                                                <span className="block text-[11px] text-ink-faint">{t('finance.billing.settledOn')} {invoice.fully_paid_at}</span>
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.billing.invoiceCreditsNet')}</dt>
                                        <dd className="font-medium text-ink">{formatMoney(invoice.total_incl_tax)} · -{formatMoney(invoice.credited_amount)} · {formatMoney(invoice.net_total_incl_tax)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.paid')}</dt>
                                        <dd className="text-ink-muted">{formatMoney(invoice.paid_amount)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.billing.balance')}</dt>
                                        <dd className="font-medium text-ink">{formatMoney(invoice.outstanding)}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={invoices.links} />
        </ApplicationShell>
    );
}
