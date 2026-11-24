import { withFinanceReturn } from '@/components/shell/FinanceReturnLink';
import { FinancePlaceholder, SectionTabs, useCurrentPath, useSection } from '@/components/finance/FinanceClientsKit';
import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import { MetricCard } from '@/components/ui/MetricCard';
import PageHeader from '@/components/ui/PageHeader';
import EmptyState from '@/components/ui/EmptyState';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatBusinessDate, formatMoney } from '@/utils/format';
import { Head, Link, usePage } from '@inertiajs/react';

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
    settlement_status?: 'unpaid' | 'partial' | 'paid' | 'settled_by_credit_note' | 'settled_mixed';
    discount_total?: string;
    net_excl_tax?: string;
    subtotal_excl_tax?: string;
    tax_total?: string;
    due_date?: string | null;
    is_overdue?: boolean;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    invoices: { data: InvoiceRow[]; links: LinkData[]; total: number; from?: number | null; to?: number | null };
    counts: { invoices: number; credit_notes: number };
    summary?: { gross: string; credit_notes: string; net: string };
    creditNotes?: { data: CreditNoteRow[]; links: LinkData[]; total: number; from?: number | null; to?: number | null } | null;
    unbilledOrders?: { data: UnbilledRow[]; links: LinkData[]; total: number; from?: number | null; to?: number | null } | null;
};
type CreditNoteRow = { id: number; credit_note_number: string | null; date: string; subtotal_excl_tax: string; discount_total: string; net_excl_tax: string; tax_total: string; total_incl_tax: string; reason: string; invoice_id: number; invoice_number: string | null; customer: string };
type UnbilledRow = { id: number; order_number: string; sale_date: string; due_date: string | null; is_overdue: boolean; customer: string; total_incl_tax: string; paid_amount: string; outstanding: string };

export default function FinanceFacturation({ organization, period, periodLabel, storeId, stores, invoices, summary, counts, creditNotes, unbilledOrders }: Props) {
    const { t } = useI18n();
    const path = useCurrentPath();
    const current = usePage().url;
    const section = useSection(['invoices', 'credit_notes', 'unbilled', 'summary'] as const, 'invoices');
    const sections = [{ key: 'invoices', label: t('fc.inv.s.invoices') }, { key: 'credit_notes', label: t('fc.inv.s.creditNotes') }, { key: 'unbilled', label: t('fc.inv.s.unbilled') }, { key: 'summary', label: t('fc.inv.s.summary') }];
    const statusLabel = (status: NonNullable<InvoiceRow['settlement_status']>) => status === 'paid' ? t('finance.billing.settled') : status === 'partial' ? t('finance.partiallyPaid')
        : status === 'settled_by_credit_note' ? t('p3.inv.settled.credit') : status === 'settled_mixed' ? t('p3.inv.settled.mixed') : t('finance.unpaid');
    const settlement = (invoice: InvoiceRow) => invoice.settlement_status ?? invoice.status;
    const badge = (invoice: InvoiceRow) => (settlement(invoice) === 'settled_by_credit_note' || settlement(invoice) === 'settled_mixed' ? 'paid' : invoice.status);
    const htTitle = (invoice: InvoiceRow) => (invoice.subtotal_excl_tax && invoice.discount_total !== undefined ? t('p3.inv.htDetail').replace('{gross}', formatMoney(invoice.subtotal_excl_tax)).replace('{discount}', formatMoney(invoice.discount_total)) : undefined);
    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.billing.title')} — Finance`} />
            <PageHeader
                title={t('finance.billing.title')}
                description={`${organization.name} · ${periodLabel} · ${counts.invoices} ${t('p3.inv.countIssued')} · ${counts.credit_notes} ${t('p3.inv.countCredit')} — ${t('finance.billing.currentDocumentHelp')}`}
            />
            <SectionTabs sections={sections} active={section} label={t('fc.sections')} />
            <FinanceFilters path={path} extra={{ section }} period={period} storeId={storeId} stores={stores} />

            {section === 'invoices' && (
                <>
            {/* Desktop/tablet: table */}
            <TableShell
                label={t('finance.billing.title')}
                state={invoices.data.length === 0 ? 'empty' : 'ready'}
                emptyTitle={t('finance.billing.empty')}
                className="hidden md:block"
                footer={invoices.data.length === 0 ? undefined : <TablePager links={invoices.links} from={invoices.from} to={invoices.to} total={invoices.total} />}
            >
                <table className="erp-table min-w-[1380px]">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('finance.billing.invoiceNumber')}</th>
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="px-4 py-2.5">{t('fc2.due')}</th>
                                    <th className="num px-4 py-2.5">{t('p3.inv.htNet')}</th>
                                    <th className="num px-4 py-2.5">{t('fc2.tva')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.billing.grossInvoice')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.billing.creditNotes')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.billing.netBilled')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.paid')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.billing.balance')}</th>
                                    <th className="px-4 py-2.5">{t('common.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.data.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">
                                            <Link href={withFinanceReturn(`/invoices/${invoice.id}`, current)}>{invoice.invoice_number}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{formatBusinessDate(invoice.invoice_date)}</td>
                                        <td className="px-4 py-2.5">{invoice.customer_company || invoice.customer_name || '—'}</td>
                                        <td className={`px-4 py-2.5 ${invoice.is_overdue ? 'text-danger' : 'text-ink-muted'}`}>{formatBusinessDate(invoice.due_date)}{invoice.is_overdue && ` · ${t('fc2.col.overdue')}`}</td>
                                        <td className="num px-4 py-2.5" dir="ltr" title={htTitle(invoice)}>{invoice.net_excl_tax ? formatMoney(invoice.net_excl_tax) : '—'}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{invoice.tax_total ? formatMoney(invoice.tax_total) : '—'}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(invoice.total_incl_tax)}</td>
                                        <td className="num px-4 py-2.5 text-danger" dir="ltr">-{formatMoney(invoice.credited_amount)}</td>
                                        <td className="num px-4 py-2.5 font-medium" dir="ltr">{formatMoney(invoice.net_total_incl_tax)}</td>
                                        <td className="num px-4 py-2.5 text-ink-muted" dir="ltr">{formatMoney(invoice.paid_amount)}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(invoice.outstanding)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">
                                            <StatusBadge status={badge(invoice)}>{statusLabel(settlement(invoice))}</StatusBadge>
                                            {invoice.status === 'paid' && invoice.fully_paid_at && (
                                                <span className="ms-1 text-[11px] text-ink-faint">({formatBusinessDate(invoice.fully_paid_at)})</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
            </TableShell>

            {/* Mobile: stacked cards */}
            <div className="space-y-3 md:hidden">
                {invoices.data.length === 0 ? (
                    <EmptyState compact title={t('finance.billing.empty')} />
                ) : (
                    <>
                        <ul className="space-y-3">
                        {invoices.data.map((invoice) => (
                            <li key={invoice.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={withFinanceReturn(`/invoices/${invoice.id}`, current)} className="block truncate text-sm font-semibold text-ink">
                                            {invoice.invoice_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">{invoice.customer_company || invoice.customer_name || '—'}</p>
                                    </div>
                                    <StatusBadge status={badge(invoice)}>{statusLabel(settlement(invoice))}</StatusBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('common.date')}</dt>
                                        <dd className="text-ink-muted">
                                            {formatBusinessDate(invoice.invoice_date)}
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
                        <TablePager links={invoices.links} from={invoices.from} to={invoices.to} total={invoices.total} />
                    </>
                )}
            </div>
                </>
            )}

            {section === 'credit_notes' && (
                <>
                    {summary && (
                        <div className="mb-4 grid gap-3 sm:grid-cols-3">
                            <MetricCard level="secondary" label={t('fc.inv.m.gross')} value={formatMoney(summary.gross)} />
                            <MetricCard level="secondary" label={t('fc.inv.m.creditNotes')} value={formatMoney(summary.credit_notes)} tone="negative" />
                            <MetricCard level="secondary" label={t('fc.inv.m.net')} value={formatMoney(summary.net)} />
                        </div>
                    )}
                    <TableShell
                        label={t('fc.inv.s.creditNotes')}
                        state={!creditNotes || creditNotes.data.length === 0 ? 'empty' : 'ready'}
                        emptyTitle={t('fc2.inv.noCreditNotes')}
                        footer={creditNotes && creditNotes.data.length > 0 ? <TablePager links={creditNotes.links} from={creditNotes.from} to={creditNotes.to} total={creditNotes.total} /> : undefined}
                    >
                        <table className="erp-table min-w-[960px]">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('fc2.inv.creditNoteNumber')}</th><th className="px-4 py-2.5">{t('common.date')}</th><th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="px-4 py-2.5">{t('finance.billing.invoiceNumber')}</th><th className="num px-4 py-2.5">{t('p3.inv.htNet')}</th><th className="num px-4 py-2.5">{t('fc2.tva')}</th><th className="num px-4 py-2.5">{t('fc.acct.ttc')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(creditNotes?.data ?? []).map((note) => (
                                    <tr key={note.id}>
                                        <td className="px-4 py-2.5 font-medium"><Link href={`/credit-notes/${note.id}`}>{note.credit_note_number ?? '—'}</Link></td>
                                        <td className="px-4 py-2.5 text-ink-muted">{formatBusinessDate(note.date)}</td>
                                        <td className="px-4 py-2.5">{note.customer}</td>
                                        <td className="px-4 py-2.5"><Link href={withFinanceReturn(`/invoices/${note.invoice_id}`, current)}>{note.invoice_number ?? '—'}</Link></td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(note.net_excl_tax)}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(note.tax_total)}</td>
                                        <td className="num px-4 py-2.5 text-danger" dir="ltr">-{formatMoney(note.total_incl_tax)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableShell>
                </>
            )}
            {section === 'unbilled' && (
                <TableShell
                    label={t('fc.inv.s.unbilled')}
                    state={!unbilledOrders || unbilledOrders.data.length === 0 ? 'empty' : 'ready'}
                    emptyTitle={t('fc2.inv.noUnbilled')}
                    footer={unbilledOrders && unbilledOrders.data.length > 0 ? <TablePager links={unbilledOrders.links} from={unbilledOrders.from} to={unbilledOrders.to} total={unbilledOrders.total} /> : undefined}
                >
                    <table className="erp-table min-w-[860px]">
                        <thead>
                            <tr>
                                <th className="px-4 py-2.5">{t('fc2.settle.order')}</th><th className="px-4 py-2.5">{t('common.date')}</th><th className="px-4 py-2.5">{t('fc2.due')}</th><th className="px-4 py-2.5">{t('common.customer')}</th>
                                <th className="num px-4 py-2.5">{t('fc.acct.ttc')}</th><th className="num px-4 py-2.5">{t('finance.paid')}</th><th className="num px-4 py-2.5">{t('fc2.settle.remaining')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(unbilledOrders?.data ?? []).map((order) => (
                                <tr key={order.id}>
                                    <td className="px-4 py-2.5 font-medium"><Link href={`/documents/open/sales_order/${order.id}`} method="post" as="button" className="text-start hover:underline">{order.order_number}</Link></td>
                                    <td className="px-4 py-2.5 text-ink-muted">{formatBusinessDate(order.sale_date)}</td>
                                    <td className={`px-4 py-2.5 ${order.is_overdue ? 'text-danger' : 'text-ink-muted'}`}>{order.due_date ? formatBusinessDate(order.due_date) : t('fc2.undated')}{order.is_overdue && ` · ${t('fc2.col.overdue')}`}</td>
                                    <td className="px-4 py-2.5">{order.customer}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{formatMoney(order.total_incl_tax)}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{formatMoney(order.paid_amount)}</td>
                                    <td className="num px-4 py-2.5 font-medium" dir="ltr">{formatMoney(order.outstanding)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableShell>
            )}
            {section === 'summary' &&
                (summary ? (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <MetricCard label={t('fc.inv.m.gross')} value={formatMoney(summary.gross)} />
                        <MetricCard label={t('fc.inv.m.creditNotes')} value={formatMoney(summary.credit_notes)} tone="negative" />
                        <MetricCard label={t('fc.inv.m.net')} value={formatMoney(summary.net)} helper={t('fc.inv.m.net.help')} />
                    </div>
                ) : (
                    <FinancePlaceholder />
                ))}
        </ApplicationShell>
    );
}
