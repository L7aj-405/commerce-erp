import {
    PriorityBadge,
    RouteLabel,
    TransferStatusBadge,
    TransferTypeBadge,
    useTransferLabels,
    type WarehouseRef,
} from '@/components/inventory/TransferRequestUi';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link, useForm } from '@inertiajs/react';

type Warehouse = { id: number; name: string; code: string };
type Row = {
    id: number;
    request_number: string;
    status: string;
    type: string;
    priority: string;
    source: WarehouseRef;
    destination: WarehouseRef;
    sales_order: { id: number; order_number: string } | null;
    reasons: string[];
    /** Carries protected customer stock; false on an order-linked replenishment-only request. */
    customer_transfer: boolean;
    product_count: number;
    unit_count: number;
    restored: boolean;
    created_at: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string; type?: string; priority?: string; warehouse_id?: string | number };
type Props = {
    requests: { data: Row[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: Filters;
    summary: { requested: number; preparing: number; shipped: number; customer_open: number };
    warehouses: Warehouse[];
    can: { manage: boolean; receive: boolean };
};


export default function TransferRequestIndex({ requests, filters, summary, warehouses, can }: Props) {
    const { t } = useI18n();
    const labels = useTransferLabels();
    const replenish = useForm({ warehouse_id: warehouses[0]?.id ?? 0 });
    const current = {
        search: filters.search,
        status: filters.status,
        type: filters.type,
        priority: filters.priority,
        warehouse_id: filters.warehouse_id ? String(filters.warehouse_id) : undefined,
    };
    // Exactly the params TransferRequestController@index validates.
    const f = useQueryFilters('/inventory/transfer-requests', current, { keys: ['search', 'status', 'type', 'priority', 'warehouse_id'] });

    const statusOptions = [{ value: 'open', label: t('inventory.trq.statusOpen') }, ...Object.entries(labels.status).map(([value, label]) => ({ value, label }))];
    const typeOptions = Object.entries(labels.type).map(([value, label]) => ({ value, label }));
    const priorityOptions = [
        { value: 'high', label: t('inventory.trq.priorityHigh') },
        { value: 'normal', label: t('inventory.trq.priorityNormal') },
    ];
    const warehouseOptions = warehouses.map((w) => ({ value: String(w.id), label: w.name }));
    const pick = (options: Array<{ value: string; label: string }>, value?: string) => options.find((o) => o.value === value)?.label ?? value ?? '';
    const chips: FilterChip[] = [];
    if (current.search) chips.push({ key: 'search', label: t('filters.search'), value: current.search, onRemove: () => f.remove('search') });
    if (current.status) chips.push({ key: 'status', label: t('common.status'), value: pick(statusOptions, current.status), onRemove: () => f.remove('status') });
    if (current.type) chips.push({ key: 'type', label: t('inventory.trq.type'), value: pick(typeOptions, current.type), onRemove: () => f.remove('type') });
    if (current.warehouse_id) chips.push({ key: 'warehouse_id', label: t('inventory.trq.warehouse'), value: pick(warehouseOptions, current.warehouse_id), onRemove: () => f.remove('warehouse_id') });
    if (current.priority) chips.push({ key: 'priority', label: t('inventory.trq.priority'), value: pick(priorityOptions, current.priority), onRemove: () => f.remove('priority') });
    const filtered = f.activeCount > 0;
    const highOpen = (r: Row) => r.priority === 'high' && r.status !== 'received' && r.status !== 'cancelled';

    /** The one thing to do next on this request, given status and permissions. */
    const nextAction = (r: Row): { label: string; primary: boolean } | null => {
        if (r.status === 'requested' && can.manage) return { label: t('inventory.requests.prepare'), primary: r.priority === 'high' };
        if (r.status === 'preparing' && can.manage) return { label: t('inventory.trq.nextShip'), primary: true };
        if (r.status === 'shipped' && can.receive) return { label: t('inventory.trq.nextReceive'), primary: true };
        return null;
    };

    const tiles: Array<{ key: string; label: string; value: number; filter: Record<string, string | undefined>; active: boolean; tone: string }> = [
        { key: 'customer', label: t('inventory.trq.summaryCustomer'), value: summary.customer_open, filter: { priority: 'high', status: 'open' }, active: filters.priority === 'high' && filters.status === 'open', tone: 'text-danger' },
        { key: 'requested', label: t('inventory.trq.summaryRequested'), value: summary.requested, filter: { status: 'requested', priority: undefined }, active: filters.status === 'requested', tone: 'text-warning' },
        { key: 'preparing', label: t('inventory.trq.summaryPreparing'), value: summary.preparing, filter: { status: 'preparing', priority: undefined }, active: filters.status === 'preparing', tone: 'text-info' },
        { key: 'shipped', label: t('inventory.trq.summaryShipped'), value: summary.shipped, filter: { status: 'shipped', priority: undefined }, active: filters.status === 'shipped', tone: 'text-primary' },
    ];

    return (
        <InventoryLayout>
            <Head title={t('inventory.requests.title')} />
            <PageHeader
                title={t('inventory.requests.title')}
                description={t('inventory.requests.description')}
                actions={can.manage && warehouses.length > 1 ? (
                    <ButtonLink href="/inventory/transfer-requests/create">+ {t('inventory.requestForm.createTitle')}</ButtonLink>
                ) : undefined}
            />

            {/* Open work at a glance — each tile is also a quick filter */}
            <div className="mb-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <button
                        key={tile.key}
                        type="button"
                        aria-pressed={tile.active}
                        onClick={() => f.patch(tile.active ? { status: undefined, priority: undefined } : tile.filter)}
                        className={`surface-card px-4 py-3 text-start transition-soft focus-visible:outline-2 ${tile.active ? 'ring-1 ring-primary/50' : 'hover:border-line-strong'}`}
                    >
                        <p className="text-[13px] font-medium text-ink-muted">{tile.label}</p>
                        <p className={`metric-value mt-1 text-2xl font-bold tracking-[-0.02em] ${tile.value > 0 ? tile.tone : 'text-ink-muted'}`}>{tile.value}</p>
                    </button>
                ))}
            </div>

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('inventory.requests.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('common.status')} allLabel={t('common.allStatuses')} options={statusOptions} value={current.status} onChange={(value) => f.set('status', value)} />
                    <ChoiceFilter label={t('inventory.trq.type')} allLabel={t('inventory.trq.allTypes')} options={typeOptions} value={current.type} onChange={(value) => f.set('type', value)} />
                    <ChoiceFilter label={t('inventory.trq.warehouse')} allLabel={t('inventory.trq.allWarehouses')} options={warehouseOptions} value={current.warehouse_id} onChange={(value) => f.set('warehouse_id', value)} />
                    <ChoiceFilter label={t('inventory.trq.priority')} allLabel={t('inventory.trq.allPriorities')} options={priorityOptions} value={current.priority} onChange={(value) => f.set('priority', value)} />
                </AdvancedFilterBar>
            </div>

            {can.manage && warehouses.length > 0 && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        replenish.post('/inventory/transfer-requests/replenish', { preserveScroll: true });
                    }}
                    className="surface-card mb-4 flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-end"
                >
                    <label className="min-w-0 flex-1 sm:max-w-sm sm:min-w-64">
                        <span className="mb-1.5 block text-[13px] font-medium text-ink-muted">{t('inventory.requests.warehouseEvaluate')}</span>
                        <select value={replenish.data.warehouse_id} onChange={(e) => replenish.setData('warehouse_id', Number(e.target.value))} className="control-select w-full">
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name} · {w.code}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button type="submit" variant="secondary" loading={replenish.processing} loadingText={t('inventory.requests.evaluating')}>
                        {t('inventory.requests.evaluate')}
                    </Button>
                    <p className="text-[13px] text-ink-muted sm:basis-full lg:ms-2 lg:max-w-sm lg:basis-auto">{t('inventory.trq.evaluateHelp')}</p>
                </form>
            )}

            {requests.data.length === 0 ? (
                <EmptyState
                    title={filtered ? t('inventory.trq.noMatch') : t('inventory.requests.empty')}
                    description={filtered ? t('inventory.trq.noMatchHelp') : t('inventory.requests.emptyHelp')}
                    actions={
                        filtered ? (
                            <Button variant="secondary" onClick={f.reset}>
                                {t('filters.reset')}
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    {/* Desktop / tablet */}
                    <TableShell
                        label={t('inventory.requests.title')}
                        busy={f.loading}
                        className="hidden xl:block"
                        footer={<TablePager links={requests.links} from={requests.from} to={requests.to} total={requests.total} />}
                    >
                            <table className="erp-table min-w-[860px] text-sm">
                                <thead>
                                    <tr>
                                        <th scope="col" className="px-4 py-2.5">{t('inventory.trq.request')}</th>
                                        <th scope="col" className="px-4 py-2.5">{t('inventory.requests.route')}</th>
                                        <th scope="col" className="px-4 py-2.5">{t('inventory.requests.order')}</th>
                                        <th scope="col" className="num px-4 py-2.5">{t('inventory.trq.contents')}</th>
                                        <th scope="col" className="px-4 py-2.5">{t('common.status')}</th>
                                        <th scope="col" className="px-4 py-2.5">{t('inventory.requests.createdAt')}</th>
                                        <th scope="col" aria-label={t('inventory.trq.nextAction')} className="px-4 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {requests.data.map((r) => {
                                        const next = nextAction(r);
                                        return (
                                            <tr key={r.id} className={`align-top ${highOpen(r) ? 'shadow-[inset_3px_0_0_var(--color-danger)] rtl:shadow-[inset_-3px_0_0_var(--color-danger)]' : ''}`}>
                                                <td className="px-4 py-3">
                                                    <Link href={`/inventory/transfer-requests/${r.id}`} className="font-semibold text-ink hover:underline focus-visible:underline">
                                                        <bdi>{r.request_number}</bdi>
                                                    </Link>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                        <TransferTypeBadge type={r.type} />
                                                        {r.priority === 'high' && <PriorityBadge priority="high" />}
                                                        {r.sales_order && !r.customer_transfer && <span className="rounded-full bg-raised px-1.5 py-0.5 text-[11px] font-medium text-ink-muted">{t('inventory.trq.replenishmentOnlyTag')}</span>}
                                                        {r.restored && <span className="text-[11px] text-ink-muted">{t('inventory.trq.restoredTag')}</span>}
                                                    </div>
                                                </td>
                                                <td className="max-w-[16rem] px-4 py-3"><RouteLabel source={r.source} destination={r.destination} /></td>
                                                <td className="px-4 py-3">
                                                    {r.sales_order ? (
                                                        <Link href={`/sales/orders/${r.sales_order.id}`} className="font-medium text-ink hover:underline"><bdi>{r.sales_order.order_number}</bdi></Link>
                                                    ) : <span className="text-ink-muted">—</span>}
                                                </td>
                                                <td className="num px-4 py-3">
                                                    <p className="font-semibold text-ink">{t('inventory.trq.unitsValue').replace('{units}', formatQuantity(r.unit_count))}</p>
                                                    <p className="text-xs text-ink-muted">{t('inventory.trq.productsValue').replace('{count}', String(r.product_count))}</p>
                                                </td>
                                                <td className="px-4 py-3"><TransferStatusBadge status={r.status} /></td>
                                                <td className="whitespace-nowrap px-4 py-3 text-ink-muted">{r.created_at ? formatDate(r.created_at) : '—'}</td>
                                                <td className="px-4 py-3 text-end">
                                                    {next ? (
                                                        <ButtonLink href={`/inventory/transfer-requests/${r.id}`} size="sm" variant={next.primary ? 'primary' : 'secondary'}>{next.label}</ButtonLink>
                                                    ) : (
                                                        <ButtonLink href={`/inventory/transfer-requests/${r.id}`} size="sm" variant="ghost">{t('common.view')}</ButtonLink>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                    </TableShell>

                    {/* Mobile */}
                    <ul className={`grid gap-3 transition-opacity md:grid-cols-2 xl:hidden ${f.loading ? 'opacity-55' : ''}`} aria-busy={f.loading || undefined}>
                        {requests.data.map((r) => {
                            const next = nextAction(r);
                            return (
                                <li key={r.id} className={`surface-card p-4 ${highOpen(r) ? 'ring-1 ring-danger/35' : ''}`}>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <Link href={`/inventory/transfer-requests/${r.id}`} className="block truncate text-sm font-semibold text-ink hover:underline"><bdi>{r.request_number}</bdi></Link>
                                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                <TransferTypeBadge type={r.type} />
                                                {r.priority === 'high' && <PriorityBadge priority="high" />}
                                                {r.sales_order && !r.customer_transfer && <span className="rounded-full bg-raised px-1.5 py-0.5 text-[11px] font-medium text-ink-muted">{t('inventory.trq.replenishmentOnlyTag')}</span>}
                                            </div>
                                        </div>
                                        <TransferStatusBadge status={r.status} className="shrink-0" />
                                    </div>
                                    <div className="mt-3 text-[13px]"><RouteLabel source={r.source} destination={r.destination} compact /></div>
                                    <dl className="mt-3 grid grid-cols-3 gap-x-3 gap-y-1.5 text-[13px]">
                                        <div className="min-w-0">
                                            <dt className="text-ink-muted">{t('inventory.requests.order')}</dt>
                                            <dd className="truncate">{r.sales_order ? <bdi>{r.sales_order.order_number}</bdi> : '—'}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-muted">{t('inventory.trq.contents')}</dt>
                                            <dd className="tabular-nums">{formatQuantity(r.unit_count)} · {r.product_count} {t('inventory.trq.productsShort')}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-muted">{t('inventory.requests.createdAt')}</dt>
                                            <dd className="text-ink-muted">{r.created_at ? formatDate(r.created_at) : '—'}</dd>
                                        </div>
                                    </dl>
                                    <ButtonLink href={`/inventory/transfer-requests/${r.id}`} size="md" block variant={next?.primary ? 'primary' : 'secondary'} className="mt-4">
                                        {next?.label ?? t('common.view')}
                                    </ButtonLink>
                                </li>
                            );
                        })}
                    </ul>
                    <div className="mt-4 xl:hidden">
                        <TablePager links={requests.links} from={requests.from} to={requests.to} total={requests.total} />
                    </div>
                </>
            )}
        </InventoryLayout>
    );
}
