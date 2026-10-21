import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import SoldLines, { type SoldLine } from '@/components/finance/SoldLines';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type JournalRow = {
    id: number;
    date: string;
    invoice_number: string;
    lines: SoldLine[];
    total_incl_tax: string;
    customer: string;
    payment_method: string;
};
type LinkData = { url: string | null; label: string; active: boolean };
type AccountingEvent = { id: number; type: string; label: string; date: string; number: string; reference: string | null; customer: string; amount: string; negative: boolean; url: string };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    rows: { data: JournalRow[]; links: LinkData[]; total: number };
    events: AccountingEvent[];
};

export default function FinanceJournal({ organization, period, periodLabel, storeId, stores, rows, events }: Props) {
    const { t } = useI18n();
    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.journal.title')} — Finance`} />
            <PageHeader title={t('finance.journal.title')} description={`${organization.name} · ${periodLabel} · ${rows.total} ${t('finance.journal.invoiceCount')}`} />
            <FinanceFilters path="/finance/journal" period={period} storeId={storeId} stores={stores} />

            <section className="mb-6 overflow-x-auto rounded-card border border-line bg-surface">
                <div className="border-b border-line p-4"><h2 className="font-semibold text-ink">{t('finance.journal.events')}</h2><p className="text-xs text-ink-muted">{t('finance.journal.eventsHelp')}</p></div>
                <table className="erp-table">
                    <thead><tr><th className="px-4 py-2.5">{t('common.date')}</th><th className="px-4 py-2.5">{t('finance.journal.type')}</th><th className="px-4 py-2.5">{t('finance.journal.number')}</th><th className="px-4 py-2.5">{t('common.reference')}</th><th className="px-4 py-2.5">{t('common.customer')}</th><th className="px-4 py-2.5 text-end">{t('finance.collections.amount')}</th></tr></thead>
                    <tbody>{events.map((event) => <tr key={`${event.type}-${event.id}`} className="border-t border-line"><td className="px-4 py-2.5 text-ink-muted">{event.date}</td><td className="px-4 py-2.5">{event.label}</td><td className="bidi-isolate px-4 py-2.5 font-medium" dir="ltr"><Link href={event.url}>{event.number}</Link></td><td className="bidi-isolate px-4 py-2.5 text-ink-muted" dir="ltr">{event.reference || '—'}</td><td className="px-4 py-2.5">{event.customer}</td><td className={`metric-value px-4 py-2.5 text-end font-medium ${event.negative ? 'text-danger' : 'text-ink'}`} dir="ltr">{event.negative ? '− ' : '+ '}{formatMoney(event.amount)}</td></tr>)}</tbody>
                </table>
                {events.length === 0 && <p className="p-6 text-center text-sm text-ink-faint">{t('finance.journal.noEvent')}</p>}
            </section>

            {rows.data.length === 0 ? (
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
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('finance.billing.invoiceNumber')}</th>
                                    <th className="px-4 py-2.5">{t('finance.journal.designation')}</th>
                                    <th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.journal.totalTaxIncluded')}</th>
                                    <th className="px-4 py-2.5">{t('finance.journal.collectionMethod')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.data.map((row) => (
                                    <tr key={row.id} className="border-t border-line align-top">
                                        <td className="px-4 py-2.5 text-ink-muted">{row.date}</td>
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">
                                            <Link href={`/invoices/${row.id}`}>{row.invoice_number}</Link>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <SoldLines lines={row.lines} />
                                        </td>
                                        <td className="px-4 py-2.5">{row.customer}</td>
                                        <td className="metric-value px-4 py-2.5 text-end" dir="ltr">{formatMoney(row.total_incl_tax)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{row.payment_method}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {rows.data.map((row) => (
                            <li key={row.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <Link href={`/invoices/${row.id}`} className="min-w-0 truncate text-sm font-semibold text-ink">
                                        {row.invoice_number}
                                    </Link>
                                    <p className="metric-value shrink-0 text-sm font-semibold text-ink" dir="ltr">{formatMoney(row.total_incl_tax)}</p>
                                </div>
                                <div className="mt-2.5">
                                    <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('finance.journal.designation')}</p>
                                    <div className="mt-1">
                                        <SoldLines lines={row.lines} />
                                    </div>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('common.date')}</dt>
                                        <dd className="text-ink-muted">{row.date}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('common.customer')}</dt>
                                        <dd className="truncate text-ink-muted">{row.customer}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.collections.method')}</dt>
                                        <dd className="text-ink-muted">{row.payment_method}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={rows.links} />
        </ApplicationShell>
    );
}
