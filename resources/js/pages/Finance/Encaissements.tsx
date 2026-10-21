import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { label, paymentMethodLabel } from '@/utils/labels';
import { Head, Link } from '@inertiajs/react';

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
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    payments: { data: PaymentRow[]; links: LinkData[]; total: number };
    refunds: { data: RefundRow[]; links: LinkData[]; total: number };
};

export default function FinanceEncaissements({ organization, period, periodLabel, storeId, stores, payments, refunds }: Props) {
    const { t } = useI18n();
    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.collections.title')} — Finance`} />
            <PageHeader title={t('finance.collections.title')} description={`${organization.name} · ${periodLabel} · ${payments.total} ${t('finance.collections.paymentCount')}`} />
            <FinanceFilters path="/finance/encaissements" period={period} storeId={storeId} stores={stores} />

            {payments.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center text-sm text-ink-faint">
                    {t('finance.collections.empty')}
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="erp-table">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('finance.collections.paymentNumber')}</th>
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.method')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.account')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.order')}</th>
                                    <th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.collections.amount')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {payments.data.map((payment) => (
                                    <tr key={payment.id} className="border-t border-line">
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">
                                            <Link href={`/payments/${payment.id}`}>{payment.payment_number}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{payment.payment_date}</td>
                                        <td className="px-4 py-2.5">{label(paymentMethodLabel, payment.method)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{payment.financial_account?.name ?? '—'}</td>
                                        <td className="px-4 py-2.5">
                                            {payment.order ? <Link href={`/sales/orders/${payment.order.id}`}>{payment.order.order_number}</Link> : '—'}
                                        </td>
                                        <td className="px-4 py-2.5">{payment.order?.customer_company || payment.order?.customer_name || '—'}</td>
                                        <td className="metric-value px-4 py-2.5 text-end" dir="ltr">{formatMoney(payment.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {payments.data.map((payment) => (
                            <li key={payment.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/payments/${payment.id}`} className="block truncate text-sm font-semibold text-ink">
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
                                        <dd className="text-ink-muted">{payment.payment_date}</dd>
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
                                            {payment.order ? <Link href={`/sales/orders/${payment.order.id}`}>{payment.order.order_number}</Link> : '—'}
                                        </dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={payments.links} />

            <section className="mt-8">
                <h2 className="mb-3 text-sm font-semibold text-ink">{t('finance.collections.refunds')} · {refunds.total}</h2>
                {refunds.data.length === 0 ? (
                    <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-6 text-center text-sm text-ink-faint">
                        {t('finance.collections.noRefund')}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-card border border-line bg-surface">
                        <table className="erp-table min-w-[820px]">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('finance.collections.refundNumber')}</th>
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.originalPayment')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.methodAccount')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.order')}</th>
                                    <th className="px-4 py-2.5">{t('finance.collections.reason')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.collections.outflow')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {refunds.data.map((refund) => (
                                    <tr key={refund.id} className="border-t border-line">
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">{refund.refund_number}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{refund.refund_date}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{refund.original_payment?.payment_number ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{label(paymentMethodLabel, refund.method)} · {refund.financial_account?.name ?? '—'}</td>
                                        <td className="px-4 py-2.5">{refund.order ? <Link href={`/sales/orders/${refund.order.id}`}>{refund.order.order_number}</Link> : '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{refund.reason}</td>
                                        <td className="metric-value px-4 py-2.5 text-end font-medium text-danger" dir="ltr">− {formatMoney(refund.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination links={refunds.links} />
            </section>
        </ApplicationShell>
    );
}
