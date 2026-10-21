import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { label, paymentStatusLabel } from '@/utils/labels';
import { Head, Link } from '@inertiajs/react';

type OrderRow = {
    id: number;
    order_number: string;
    sale_date: string;
    customer: string;
    total_incl_tax: string;
    paid_amount: string;
    outstanding: string;
    payment_status: 'unpaid' | 'partially_paid';
    last_payment_date: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    asOf: string;
    storeId: number | null;
    stores: FinanceStore[];
    total: string;
    orders: { data: OrderRow[]; links: LinkData[]; total: number };
};

export default function FinanceCreances({ organization, period, periodLabel, asOf, storeId, stores, total, orders }: Props) {
    const { t } = useI18n();
    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.receivables.title')} — Finance`} />
            <PageHeader
                title={t('finance.receivables.title')}
                description={`${organization.name} · ${t('finance.receivables.balanceAt')} ${asOf} (${periodLabel}) · ${t('common.total')} : ${formatMoney(total)}`}
            />
            <FinanceFilters path="/finance/creances" period={period} storeId={storeId} stores={stores} />

            {orders.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center text-sm text-ink-faint">
                    {t('finance.receivables.empty')}
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="erp-table">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('finance.sales.orderNumber')}</th>
                                    <th className="px-4 py-2.5">{t('finance.sales.saleDate')}</th>
                                    <th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.receivables.orderTotal')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.sales.collected')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.sales.remaining')}</th>
                                    <th className="px-4 py-2.5">{t('finance.sales.paymentStatus')}</th>
                                    <th className="px-4 py-2.5">{t('finance.receivables.lastPayment')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('common.actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((order) => (
                                    <tr key={order.id} className="border-t border-line">
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">
                                            <Link href={`/sales/orders/${order.id}`}>{order.order_number}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{order.sale_date}</td>
                                        <td className="px-4 py-2.5">{order.customer}</td>
                                        <td className="metric-value px-4 py-2.5 text-end" dir="ltr">{formatMoney(order.total_incl_tax)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end text-ink-muted" dir="ltr">{formatMoney(order.paid_amount)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end font-medium" dir="ltr">{formatMoney(order.outstanding)}</td>
                                        <td className="px-4 py-2.5"><StatusBadge status={order.payment_status}>{label(paymentStatusLabel, order.payment_status)}</StatusBadge></td>
                                        <td className="px-4 py-2.5 text-ink-muted">{order.last_payment_date ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-end"><Link href={`/sales/orders/${order.id}`} className="font-medium text-ink underline-offset-4 hover:underline">{t('common.open')}</Link></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {orders.data.map((order) => (
                            <li key={order.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/sales/orders/${order.id}`} className="block truncate text-sm font-semibold text-ink">
                                            {order.order_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">{order.customer}</p>
                                    </div>
                                    <StatusBadge status={order.payment_status}>{label(paymentStatusLabel, order.payment_status)}</StatusBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.sales.saleDate')}</dt>
                                        <dd className="text-ink-muted">{order.sale_date}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.receivables.orderTotal')}</dt>
                                        <dd className="font-medium text-ink">{formatMoney(order.total_incl_tax)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.sales.collected')}</dt>
                                        <dd className="text-ink-muted">{formatMoney(order.paid_amount)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.sales.remaining')}</dt>
                                        <dd className="font-semibold text-ink">{formatMoney(order.outstanding)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.receivables.lastPayment')}</dt>
                                        <dd className="text-ink-muted">{order.last_payment_date ?? '—'}</dd>
                                    </div>
                                </dl>
                                <Link href={`/sales/orders/${order.id}`} className="mt-3 inline-flex text-sm font-medium text-ink underline-offset-4 hover:underline">{t('common.open')}</Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={orders.links} />
        </ApplicationShell>
    );
}
