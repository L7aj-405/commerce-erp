import { withFinanceReturn } from '@/components/shell/FinanceReturnLink';
import { ParentTabs, useCurrentPath, useQueryParams, useSection } from '@/components/finance/FinanceClientsKit';
import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import EmptyState from '@/components/ui/EmptyState';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Button } from '@/components/ui/Button';
import { MetricCard } from '@/components/ui/MetricCard';
import { formatBusinessDate, formatMoney } from '@/utils/format';
import { label, paymentMethodLabel } from '@/utils/labels';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type PaymentRow = {
    id: number;
    payment_number: string;
    payment_date: string;
    method: string;
    amount: string;
    financial_account: { id: number; name: string; code: string; type: string } | null;
    order: { id: number; order_number: string; customer_name: string | null; customer_company: string | null } | null;
};
type RefundRow = {
    id: number;
    refund_number: string;
    refund_date: string;
    method: string;
    amount: string;
    reason: string;
    original_payment: { id: number; payment_number: string } | null;
    financial_account: { id: number; name: string; code: string; type: string } | null;
    order: { id: number; order_number: string; customer_name: string | null; customer_company: string | null } | null;
};
type Obligation = { invoice_id: number; invoice_number: string; sales_order_id: number; order_number: string; customer_id: number | null; customer: string; currency_code: string; amount: string };
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    payments: { data: PaymentRow[]; links: LinkData[]; total: number; from?: number | null; to?: number | null };
    refunds: { data: RefundRow[]; links: LinkData[]; total: number; from?: number | null; to?: number | null };
    obligations: { data: Obligation[]; links: LinkData[]; total: number; from?: number | null; to?: number | null } | null;
    obligationsTotal: string | null;
    asOf: string;
    summary: { gross: string; refunds: string; net: string; count: number };
    settlementCustomers: { id: number; display_name: string }[];
    can: { newSettlement: boolean; accounts: boolean };
};

export default function FinanceEncaissements({ organization, period, periodLabel, storeId, stores, payments, refunds, obligations, obligationsTotal, asOf, summary, settlementCustomers, can }: Props) {
    const { t } = useI18n();
    const path = useCurrentPath();
    const current = usePage().url;
    // One authoritative register: the legacy "monthly" section is the same register and is shown as "settlements".
    const section = useSection(['settlements', 'monthly', 'refunds'] as const, 'settlements') === 'refunds' ? 'refunds' : 'settlements';
    const refundView = useQueryParams().get('view') === 'obligations' ? 'obligations' : 'done';
    const [picking, setPicking] = useState(false);
    const [customerId, setCustomerId] = useState('');
    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.collections.title')} — Finance`} />
            <PageHeader title={t('finance.collections.title')} description={`${organization.name} · ${periodLabel} · ${payments.total} ${t('finance.collections.paymentCount')}`} actions={can.newSettlement ? <Button size="sm" onClick={() => setPicking((v) => !v)} aria-expanded={picking}>{t('p3.col.newSettlement')}</Button> : undefined} />
            <ParentTabs group="collections" active={section} />
            {picking && can.newSettlement && (
                <form
                    className="surface-card mb-4 flex flex-wrap items-end gap-3 p-4"
                    onSubmit={(e) => { e.preventDefault(); if (customerId) router.get(`/finance/clients/comptes/${customerId}`, { section: 'payments' }); }}
                >
                    <p className="w-full text-sm text-ink-muted">{t('p3.col.pickCustomer')}</p>
                    <label className="flex min-w-[16rem] flex-1 flex-col gap-1 text-[13px] text-ink-muted">{t('common.customer')}
                        <select className="control-input h-9 px-2" value={customerId} onChange={(e) => setCustomerId(e.target.value)} required>
                            <option value="">{t('p3.col.customerPlaceholder')}</option>
                            {settlementCustomers.map((c) => <option key={c.id} value={c.id}>{c.display_name}</option>)}
                        </select>
                    </label>
                    <Button type="submit" size="sm" disabled={!customerId}>{t('p3.col.continue')}</Button>
                </form>
            )}
            <FinanceFilters path={path} extra={{ section }} period={period} storeId={storeId} stores={stores} />
            <section className="mb-4" aria-label={t('p3.col.sum.scope')}>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard level="secondary" label={t('p3.col.sum.gross')} value={formatMoney(summary.gross)} tone="positive" />
                    <MetricCard level="secondary" label={t('p3.col.sum.refunds')} value={formatMoney(summary.refunds)} tone="negative" />
                    <MetricCard level="secondary" label={t('p3.col.sum.net')} value={formatMoney(summary.net)} />
                    <MetricCard level="secondary" label={t('p3.col.sum.count')} value={String(summary.count)} />
                </div>
                <p className="mt-2 text-[13px] text-ink-muted">{t('p3.col.sum.scope')}</p>
            </section>

            {section === 'settlements' && (
                <>
            {/* Desktop/tablet: table */}
            <TableShell
                label={t('finance.collections.title')}
                state={payments.data.length === 0 ? 'empty' : 'ready'}
                emptyTitle={t('finance.collections.empty')}
                className="hidden md:block"
                footer={payments.data.length === 0 ? undefined : <TablePager links={payments.links} from={payments.from} to={payments.to} total={payments.total} />}
            >
                <table className="erp-table min-w-[860px]">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('finance.collections.paymentNumber')}</th>
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.method')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.account')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.order')}</th>
                                    <th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.collections.amount')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {payments.data.map((payment) => (
                                    <tr key={payment.id}>
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">
                                            <Link href={withFinanceReturn(`/payments/${payment.id}`, current)}>{payment.payment_number}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{formatBusinessDate(payment.payment_date)}</td>
                                        <td className="px-4 py-2.5">{label(paymentMethodLabel, payment.method)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{payment.financial_account?.name ?? '—'}</td>
                                        <td className="px-4 py-2.5">
                                            {payment.order ? <Link href={withFinanceReturn(`/sales/orders/${payment.order.id}`, current)}>{payment.order.order_number}</Link> : '—'}
                                        </td>
                                        <td className="px-4 py-2.5">{payment.order?.customer_company || payment.order?.customer_name || '—'}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(payment.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
            </TableShell>

            {/* Mobile: stacked cards */}
            <div className="space-y-3 md:hidden">
                {payments.data.length === 0 ? (
                    <EmptyState compact title={t('finance.collections.empty')} />
                ) : (
                    <>
                        <ul className="space-y-3">
                        {payments.data.map((payment) => (
                            <li key={payment.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={withFinanceReturn(`/payments/${payment.id}`, current)} className="block truncate text-sm font-semibold text-ink">
                                            {payment.payment_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">
                                            {payment.order?.customer_company || payment.order?.customer_name || '—'}
                                        </p>
                                    </div>
                                    <p className="metric-value shrink-0 text-sm font-semibold text-ink" dir="ltr">{formatMoney(payment.amount)}</p>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('common.date')}</dt>
                                        <dd className="text-ink-muted">{formatBusinessDate(payment.payment_date)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.collections.method')}</dt>
                                        <dd className="text-ink-muted">{label(paymentMethodLabel, payment.method)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.collections.account')}</dt>
                                        <dd className="truncate text-ink-muted">{payment.financial_account?.name ?? '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.collections.order')}</dt>
                                        <dd className="truncate">
                                            {payment.order ? <Link href={withFinanceReturn(`/sales/orders/${payment.order.id}`, current)}>{payment.order.order_number}</Link> : '—'}
                                        </dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                        <TablePager links={payments.links} from={payments.from} to={payments.to} total={payments.total} />
                    </>
                )}
            </div>

                </>
            )}

            {section === 'refunds' && (
                <nav aria-label={t('finance.collections.refunds')} className="mb-4 flex gap-2">
                    {(['done', 'obligations'] as const).map((v) => (
                        <Link key={v} href={`${path}?section=refunds&view=${v}&month=${period}${storeId ? `&store_id=${storeId}` : ''}`} aria-current={refundView === v ? 'page' : undefined}
                            className={`rounded-full border px-3 py-1 text-sm ${refundView === v ? 'border-ink bg-ink text-surface' : 'border-line text-ink-muted hover:text-ink'}`}>{v === 'done' ? t('p3.ref.done') : t('p3.ref.obligations')}</Link>
                    ))}
                </nav>
            )}

            {section === 'refunds' && refundView === 'obligations' && (
                <section aria-labelledby="obl-title">
                    <h2 id="obl-title" className="mb-1 text-base font-semibold text-ink">{t('p3.ref.total')} : <span dir="ltr">{formatMoney(obligationsTotal ?? '0')}</span></h2>
                    <p className="mb-3 text-[13px] text-ink-muted">{t('p3.ref.obligationsHelp').replace('{date}', formatBusinessDate(asOf))}</p>
                    <TableShell label={t('p3.ref.obligations')} state={!obligations || obligations.data.length === 0 ? 'empty' : 'ready'} emptyTitle={t('p3.ref.empty')}
                        footer={obligations && obligations.data.length > 0 ? <TablePager links={obligations.links} from={obligations.from} to={obligations.to} total={obligations.total} /> : undefined}>
                        <table className="erp-table min-w-[820px]">
                            <thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="px-4 py-2.5">{t('p3.ref.col.source')}</th><th className="px-4 py-2.5">{t('p3.ref.col.status')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th><th className="px-4 py-2.5"><span className="sr-only">{t('common.actions')}</span></th></tr></thead>
                            <tbody>
                                {(obligations?.data ?? []).map((o) => (
                                    <tr key={o.invoice_id}>
                                        <td className="px-4 py-2.5">{o.customer_id && can.accounts ? <Link href={withFinanceReturn(`/finance/clients/comptes/${o.customer_id}?section=summary`, current)}>{o.customer}</Link> : o.customer}</td>
                                        <td className="px-4 py-2.5"><Link href={withFinanceReturn(`/invoices/${o.invoice_id}`, current)}>{o.invoice_number}</Link> · <Link href={withFinanceReturn(`/sales/orders/${o.sales_order_id}`, current)}>{o.order_number}</Link></td>
                                        <td className="px-4 py-2.5 text-warning">{t('p3.ref.status')}</td>
                                        <td className="num px-4 py-2.5 font-medium" dir="ltr">{formatMoney(o.amount, o.currency_code)}</td>
                                        <td className="px-4 py-2.5 text-end"><Link className="text-[13px] underline" href={withFinanceReturn(`/sales/orders/${o.sales_order_id}`, current)}>{t('p3.ref.openOrder')}</Link></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableShell>
                </section>
            )}

            {section === 'refunds' && refundView === 'done' && (
            <section className="mt-8" aria-labelledby="refunds-title">
                <h2 id="refunds-title" className="mb-3 text-base font-semibold text-ink">{t('finance.collections.refunds')} · <span className="tabular-nums">{refunds.total}</span></h2>
                <TableShell
                    label={t('finance.collections.refunds')}
                    state={refunds.data.length === 0 ? 'empty' : 'ready'}
                    emptyTitle={t('finance.collections.noRefund')}
                    footer={refunds.data.length === 0 ? undefined : <TablePager links={refunds.links} from={refunds.from} to={refunds.to} total={refunds.total} />}
                >
                    <table className="erp-table min-w-[820px]">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('finance.collections.refundNumber')}</th>
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.originalPayment')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.methodAccount')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.order')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.reason')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.collections.outflow')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {refunds.data.map((refund) => (
                                    <tr key={refund.id}>
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">{refund.refund_number}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{formatBusinessDate(refund.refund_date)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{refund.original_payment?.payment_number ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{label(paymentMethodLabel, refund.method)} · {refund.financial_account?.name ?? '—'}</td>
                                        <td className="px-4 py-2.5">{refund.order ? <Link href={withFinanceReturn(`/sales/orders/${refund.order.id}`, current)}>{refund.order.order_number}</Link> : '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{refund.reason}</td>
                                        <td className="num px-4 py-2.5 font-medium text-danger" dir="ltr">− {formatMoney(refund.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                </TableShell>
            </section>
            )}
        </ApplicationShell>
    );
}
