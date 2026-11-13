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
import {
    fulfillmentLabel,
    fulfillmentTone,
    label,
    orderStatusLabel,
    orderStatusTone,
    paymentStatusLabel,
    paymentStatusTone,
    sourceLabel,
} from '@/utils/labels';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Order = {
    id: number;
    order_number: string;
    sale_date: string;
    customer_name: string | null;
    customer_company: string | null;
    status: string;
    fulfillment_status: string;
    payment_status: string;
    total_incl_tax: string;
    source: string;
    currency_code: string;
    has_active_invoice: boolean;
    returned_total: string;
    net_total: string;
    return_state: 'none' | 'partial' | 'full';
};
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string; fulfillment_status?: string; payment_status?: string; sale_date?: string };
type Props = {
    orders: { data: Order[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number };
    filters: Filters;
    summary: { total: number; a_preparer: number; partiellement_payees: number; payees: number };
};

// Exactly the single-value params SalesOrderController@index validates.
const FILTER_KEYS = ['search', 'status', 'fulfillment_status', 'payment_status', 'sale_date'] as const;

export default function OrderIndex({ orders, filters, summary }: Props) {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const can = (permission: string) => tenant.permissions.includes(permission);
    const canInvoice = can('invoices.create');
    const [invoicing, setInvoicing] = useState<number | null>(null);
    const f = useQueryFilters('/sales/orders', filters, { keys: [...FILTER_KEYS] });
    const cleared = Object.fromEntries(FILTER_KEYS.map((key) => [key, undefined])) as Filters;

    const statusOptions = ['draft', 'confirmed', 'cancelled'].map((value) => ({ value, label: label(orderStatusLabel, value) }));
    const fulfillmentOptions = [
        { value: 'unfulfilled', label: t('sales.orders.toPrepare') },
        { value: 'partially_fulfilled', label: t('sales.orders.partialPreparation') },
        { value: 'fulfilled', label: t('sales.orders.delivered') },
    ];
    const paymentOptions = ['unpaid', 'partially_paid', 'paid'].map((value) => ({ value, label: label(paymentStatusLabel, value) }));
    const optionLabel = (options: { value: string; label: string }[], value: string) => options.find((option) => option.value === value)?.label ?? value;

    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: optionLabel(statusOptions, filters.status), onRemove: () => f.remove('status') });
    if (filters.fulfillment_status)
        chips.push({
            key: 'fulfillment_status',
            label: t('sales.orders.preparation'),
            value: optionLabel(fulfillmentOptions, filters.fulfillment_status),
            onRemove: () => f.remove('fulfillment_status'),
        });
    if (filters.payment_status)
        chips.push({ key: 'payment_status', label: t('common.payment'), value: optionLabel(paymentOptions, filters.payment_status), onRemove: () => f.remove('payment_status') });
    if (filters.sale_date) chips.push({ key: 'sale_date', label: t('common.date'), value: formatDate(filters.sale_date), onRemove: () => f.remove('sale_date') });

    // Each tile applies exactly the condition its count uses server-side.
    const tiles: { key: string; label: string; value: number; filters: Filters; tone: 'neutral' | 'warning' | 'success' }[] = [
        { key: 'total', label: t('sales.orders.total'), value: summary.total, filters: {}, tone: 'neutral' },
        { key: 'prepare', label: t('sales.orders.toPrepare'), value: summary.a_preparer, filters: { status: 'confirmed', fulfillment_status: 'unfulfilled' }, tone: 'warning' },
        { key: 'partial', label: t('sales.orders.partiallyPaid'), value: summary.partiellement_payees, filters: { payment_status: 'partially_paid' }, tone: 'warning' },
        { key: 'paid', label: t('sales.orders.paid'), value: summary.payees, filters: { payment_status: 'paid' }, tone: 'success' },
    ];
    const tileActive = (tile: (typeof tiles)[number]) =>
        f.activeCount === Object.keys(tile.filters).length && Object.entries(tile.filters).every(([key, value]) => filters[key as keyof Filters] === value);

    const createInvoice = (order: Order) => {
        setInvoicing(order.id);
        router.post(`/sales/orders/${order.id}/invoices`, {}, { preserveScroll: true, onFinish: () => setInvoicing(null) });
    };

    const statusBadges = (order: Order) => (
        <>
            <DocBadge tone={orderStatusTone(order.status)}>{label(orderStatusLabel, order.status)}</DocBadge>
            {order.return_state === 'partial' && <DocBadge tone="warning">{t('sales.orders.partialReturn')}</DocBadge>}
            {order.return_state === 'full' && <DocBadge tone="danger">{t('sales.orders.fullReturn')}</DocBadge>}
        </>
    );

    const rowActions = (order: Order, block = false) => (
        <div className={`flex items-center gap-2 ${block ? '' : 'justify-end'}`}>
            {order.status === 'confirmed' && !order.has_active_invoice && canInvoice && (
                <Button size="sm" variant="secondary" loading={invoicing === order.id} disabled={invoicing !== null} onClick={() => createInvoice(order)} className={block ? 'flex-1' : ''}>
                    {t('sales.orders.invoiceAction')}
                </Button>
            )}
            <ButtonLink href={`/sales/orders/${order.id}`} size="sm" variant="outline" className={block ? 'flex-1' : ''}>
                {t('common.open')}
                <span className="sr-only"> {order.order_number}</span>
            </ButtonLink>
        </div>
    );

    const filtered = f.activeCount > 0;
    const resetAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : undefined;
    const emptyTitle = filtered ? t('filters.noMatch') : t('sales.orders.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : undefined;

    return (
        <SalesLayout>
            <Head title={t('sales.orders.title')} />
            <PageHeader
                title={t('sales.orders.title')}
                description={t('sales.orders.description')}
                actions={
                    tenant.store && can('pos.access') ? (
                        <ButtonLink href="/pos">
                            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                            {t('platform.newSale')}
                        </ButtonLink>
                    ) : undefined
                }
            />

            {/* Summary tiles double as one-click filters */}
            <nav aria-label={t('sales.orders.tileHint')} className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                {tiles.map((tile) => {
                    const active = tileActive(tile);
                    return (
                        <button
                            key={tile.key}
                            type="button"
                            aria-pressed={active}
                            onClick={() => f.patch({ ...cleared, ...tile.filters })}
                            className={`surface-card relative flex cursor-pointer flex-col items-start overflow-hidden px-4 py-3 text-start transition-soft hover:border-line-strong ${
                                active ? 'border-primary/60! bg-accent-soft/40' : ''
                            }`}
                        >
                            {active && <span aria-hidden="true" className="absolute inset-y-0 start-0 w-1 bg-primary" />}
                            <span className="text-[13px] font-medium text-ink-muted">{tile.label}</span>
                            <span
                                className={`metric-value mt-1 text-2xl font-semibold ${
                                    tile.tone === 'warning' && tile.value > 0 ? 'text-warning' : tile.tone === 'success' && tile.value > 0 ? 'text-success' : 'text-ink'
                                }`}
                            >
                                {tile.value}
                            </span>
                        </button>
                    );
                })}
            </nav>

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('sales.orders.filtersLabel')}
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('sales.orders.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('common.status')} options={statusOptions} value={filters.status} onChange={(value) => f.set('status', value)} />
                    <ChoiceFilter
                        label={t('sales.orders.preparation')}
                        options={fulfillmentOptions}
                        value={filters.fulfillment_status}
                        onChange={(value) => f.set('fulfillment_status', value)}
                    />
                    <ChoiceFilter label={t('common.payment')} options={paymentOptions} value={filters.payment_status} onChange={(value) => f.set('payment_status', value)} />
                    <DateFilter label={t('common.date')} value={filters.sale_date} onChange={(value) => f.set('sale_date', value)} />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('sales.orders.title')}
                state={orders.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={resetAction}
                className="hidden xl:block"
                footer={orders.data.length === 0 ? undefined : <TablePager links={orders.links} from={orders.from} to={orders.to} total={orders.total} />}
            >
                <table className="erp-table min-w-[860px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('sales.orders.title')}</th>
                            <th className="px-4 py-2.5">{t('common.date')}</th>
                            <th className="px-4 py-2.5">{t('common.customer')}</th>
                            <th className="px-4 py-2.5">{t('common.status')}</th>
                            <th className="px-4 py-2.5">{t('sales.orders.fulfillment')}</th>
                            <th className="px-4 py-2.5">{t('common.payment')}</th>
                            <th className="num px-4 py-2.5">{t('common.total')}</th>
                            <th className="hidden px-4 py-2.5 2xl:table-cell">{t('common.source')}</th>
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.data.map((order) => {
                            const row = rowLink(`/sales/orders/${order.id}`);
                            return (
                                <tr key={order.id} onClick={row.onClick} className={row.className}>
                                    <td className="px-4 py-3">
                                        <Link href={`/sales/orders/${order.id}`} className="bidi-isolate font-semibold text-ink hover:text-accent hover:underline" dir="ltr">
                                            {order.order_number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{formatDate(order.sale_date)}</td>
                                    <td className="max-w-72 px-4 py-3">
                                        <div className="truncate font-medium text-ink">{order.customer_name ?? t('common.counterCustomer')}</div>
                                        {order.customer_company && order.customer_company !== order.customer_name && (
                                            <div className="truncate text-[13px] text-ink-muted">{order.customer_company}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-1">{statusBadges(order)}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <DocBadge tone={fulfillmentTone(order.fulfillment_status)}>{label(fulfillmentLabel, order.fulfillment_status)}</DocBadge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <DocBadge tone={paymentStatusTone(order.payment_status)}>{label(paymentStatusLabel, order.payment_status)}</DocBadge>
                                    </td>
                                    <td className="num px-4 py-3" dir="ltr">
                                        <span className={order.return_state === 'none' ? 'font-semibold text-ink' : 'text-ink-muted line-through'}>
                                            {formatMoney(order.total_incl_tax, order.currency_code)}
                                        </span>
                                        {order.return_state !== 'none' && (
                                            <span className="block text-[13px] font-semibold text-ink">Net {formatMoney(order.net_total, order.currency_code)}</span>
                                        )}
                                    </td>
                                    <td className="hidden px-4 py-3 text-ink-muted 2xl:table-cell">{label(sourceLabel, order.source)}</td>
                                    <td className="px-4 py-3">{rowActions(order)}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards (the filter bar above is shared with desktop) */}
            <div className="xl:hidden" aria-busy={f.loading || undefined}>
                {orders.data.length === 0 ? (
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={resetAction} />
                ) : (
                    <ul className={`grid gap-3 sm:grid-cols-2 lg:grid-cols-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {orders.data.map((order) => (
                            <li key={order.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/sales/orders/${order.id}`} className="block truncate text-base font-semibold text-ink hover:underline">
                                            <bdi>{order.order_number}</bdi>
                                        </Link>
                                        <p className="text-[13px] text-ink-muted">
                                            {formatDate(order.sale_date)} · {label(sourceLabel, order.source)}
                                        </p>
                                    </div>
                                    <p className="metric-value shrink-0 text-end text-base font-semibold text-ink">
                                        {formatMoney(order.return_state === 'none' ? order.total_incl_tax : order.net_total, order.currency_code)}
                                    </p>
                                </div>
                                <p className="mt-2 truncate text-sm font-medium text-ink">
                                    {order.customer_name ?? t('common.counterCustomer')}
                                    {order.customer_company && order.customer_company !== order.customer_name && (
                                        <span className="font-normal text-ink-muted"> · {order.customer_company}</span>
                                    )}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {statusBadges(order)}
                                    <DocBadge tone={fulfillmentTone(order.fulfillment_status)}>{label(fulfillmentLabel, order.fulfillment_status)}</DocBadge>
                                    <DocBadge tone={paymentStatusTone(order.payment_status)}>{label(paymentStatusLabel, order.payment_status)}</DocBadge>
                                </div>
                                {order.return_state !== 'none' && (
                                    <p className="mt-1.5 text-[13px] text-ink-muted">
                                        {t('sales.orders.initialSale')} {formatMoney(order.total_incl_tax, order.currency_code)} · {t('sales.orders.returns')} -
                                        {formatMoney(order.returned_total, order.currency_code)}
                                    </p>
                                )}
                                <div className="mt-3">{rowActions(order, true)}</div>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-4">
                    <TablePager links={orders.links} from={orders.from} to={orders.to} total={orders.total} />
                </div>
            </div>
        </SalesLayout>
    );
}
