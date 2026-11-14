import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import SoldLines, { type SoldLine } from '@/components/finance/SoldLines';
import PageHeader from '@/components/ui/PageHeader';
import EmptyState from '@/components/ui/EmptyState';
import { TablePager, TableShell } from '@/components/ui/TableShell';
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
    rows: { data: JournalRow[]; links: LinkData[]; total: number; from?: number | null; to?: number | null };
    events: AccountingEvent[];
};

export default function FinanceJournal({ organization, period, periodLabel, storeId, stores, rows, events }: Props) {
    const { t } = useI18n();
    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.journal.title')} — Finance`} />
            <PageHeader title={t('finance.journal.title')} description={`${organization.name} · ${periodLabel} · ${rows.total} ${t('finance.journal.invoiceCount')}`} />
            <FinanceFilters path="/finance/journal" period={period} storeId={storeId} stores={stores} />

            <section className="mb-6" aria-labelledby="journal-events">
                <div className="mb-3">
                    <h2 id="journal-events" className="text-base font-semibold text-ink">{t('finance.journal.events')}</h2>
                    <p className="text-[13px] text-ink-muted">{t('finance.journal.eventsHelp')}</p>
                </div>
                <TableShell label={t('finance.journal.events')} state={events.length === 0 ? 'empty' : 'ready'} emptyTitle={t('finance.journal.noEvent')}>
                    <table className="erp-table min-w-[820px]">
                        <thead>
                            <tr>
                                <th className="px-4 py-2.5">{t('common.date')}</th>
                                <th className="px-4 py-2.5">{t('finance.journal.type')}</th>
                                <th className="px-4 py-2.5">{t('finance.journal.number')}</th>
                                <th className="px-4 py-2.5">{t('common.reference')}</th>
                                <th className="px-4 py-2.5">{t('common.customer')}</th>
                                <th className="num px-4 py-2.5">{t('finance.collections.amount')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((event) => (
                                <tr key={`${event.type}-${event.id}`}>
                                    <td className="whitespace-nowrap px-4 py-2.5 text-ink-muted">{event.date}</td>
                                    <td className="px-4 py-2.5">{event.label}</td>
                                    <td className="bidi-isolate px-4 py-2.5 font-medium" dir="ltr"><Link href={event.url}>{event.number}</Link></td>
                                    <td className="bidi-isolate px-4 py-2.5 text-ink-muted" dir="ltr">{event.reference || '—'}</td>
                                    <td className="px-4 py-2.5">{event.customer}</td>
                                    <td className={`num px-4 py-2.5 font-medium ${event.negative ? 'text-danger' : 'text-ink'}`} dir="ltr">{event.negative ? '− ' : '+ '}{formatMoney(event.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableShell>
            </section>

            <h2 className="mb-3 text-base font-semibold text-ink">{t('finance.journal.title')}</h2>

            {/* Desktop/tablet: table */}
            <TableShell
                label={t('finance.journal.title')}
                state={rows.data.length === 0 ? 'empty' : 'ready'}
                emptyTitle={t('finance.billing.empty')}
                className="hidden md:block"
                footer={rows.data.length === 0 ? undefined : <TablePager links={rows.links} from={rows.from} to={rows.to} total={rows.total} />}
            >
                <table className="erp-table min-w-[900px]">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('finance.billing.invoiceNumber')}</th>
                                    <th className="px-4 py-2.5">{t('finance.journal.designation')}</th>
                                    <th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.journal.totalTaxIncluded')}</th>
                                    <th className="px-4 py-2.5">{t('finance.journal.collectionMethod')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.data.map((row) => (
                                    <tr key={row.id} className="align-top">
                                        <td className="px-4 py-2.5 text-ink-muted">{row.date}</td>
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">
                                            <Link href={`/invoices/${row.id}`}>{row.invoice_number}</Link>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <SoldLines lines={row.lines} />
                                        </td>
                                        <td className="px-4 py-2.5">{row.customer}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(row.total_incl_tax)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{row.payment_method}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
            </TableShell>

            {/* Mobile: stacked cards */}
            <div className="space-y-3 md:hidden">
                {rows.data.length === 0 ? (
                    <EmptyState compact title={t('finance.billing.empty')} />
                ) : (
                    <>
                        <ul className="space-y-3">
                        {rows.data.map((row) => (
                            <li key={row.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <Link href={`/invoices/${row.id}`} className="min-w-0 truncate text-sm font-semibold text-ink">
                                        {row.invoice_number}
                                    </Link>
                                    <p className="metric-value shrink-0 text-sm font-semibold text-ink" dir="ltr">{formatMoney(row.total_incl_tax)}</p>
                                </div>
                                <div className="mt-2.5">
                                    <p className="text-[12px] font-medium text-ink-faint">{t('finance.journal.designation')}</p>
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
                        <TablePager links={rows.links} from={rows.from} to={rows.to} total={rows.total} />
                    </>
                )}
            </div>
        </ApplicationShell>
    );
}
