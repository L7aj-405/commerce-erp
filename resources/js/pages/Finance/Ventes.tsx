import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { formatMoney } from '@/utils/format';
import { label, paymentStatusLabel } from '@/utils/labels';
import { Head, Link } from '@inertiajs/react';

type Order = {
    id: number;
    order_number: string;
    sale_date: string;
    customer: string;
    total_incl_tax: string;
    returned_total: string;
    net_total: string;
    paid_amount: string;
    remaining_amount: string;
    payment_status: string;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    orders: { data: Order[]; links: LinkData[]; total: number };
};

export default function FinanceVentes({ organization, period, periodLabel, storeId, stores, orders }: Props) {
    const { t } = useI18n();
    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.sales.title')} — Finance`} />
            <PageHeader title={t('finance.sales.title')} description={`${organization.name} · ${periodLabel} · ${orders.total} ${t('finance.sales.confirmedCount')}`} />
            <FinanceFilters path="/finance/ventes" period={period} storeId={storeId} stores={stores} />

            {orders.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center text-sm text-ink-faint">
                    {t('finance.sales.empty')}
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
                                    <th className="px-4 py-2.5 text-end">{t('finance.sales.grossSale')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.sales.receivedReturns')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.sales.netRetained')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.sales.collected')}</th>
                                    <th className="px-4 py-2.5 text-end">{t('finance.sales.remaining')}</th>
                                    <th className="px-4 py-2.5">{t('finance.sales.paymentStatus')}</th>
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
                                        <td className="metric-value px-4 py-2.5 text-end text-danger" dir="ltr">-{formatMoney(order.returned_total)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end font-medium" dir="ltr">{formatMoney(order.net_total)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end text-ink-muted" dir="ltr">{formatMoney(order.paid_amount)}</td>
                                        <td className="metric-value px-4 py-2.5 text-end font-medium" dir="ltr">{formatMoney(order.remaining_amount)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{label(paymentStatusLabel, order.payment_status)}</td>
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
                                    <div className="metric-value shrink-0 text-end" dir="ltr"><p className="text-sm font-semibold text-ink">{formatMoney(order.net_total)}</p><p className="text-[11px] text-ink-muted">{t('finance.sales.gross')} {formatMoney(order.total_incl_tax)} · {t('sales.orders.returns')} -{formatMoney(order.returned_total)}</p></div>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.sales.collected')}</dt>
                                        <dd className="font-medium text-ink">{formatMoney(order.paid_amount)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.sales.remaining')}</dt>
                                        <dd className="font-medium text-ink">{formatMoney(order.remaining_amount)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.sales.saleDate')}</dt>
                                        <dd className="text-ink-muted">{order.sale_date}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.sales.paymentStatus')}</dt>
                                        <dd className="text-ink-muted">{label(paymentStatusLabel, order.payment_status)}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={orders.links} />
        </ApplicationShell>
    );
}
