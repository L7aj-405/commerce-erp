import {
    PriorityBadge,
    RouteLabel,
    TransferStatusBadge,
    TransferTypeBadge,
    useTransferLabels,
    type WarehouseRef,
} from '@/components/inventory/TransferRequestUi';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
    product_count: number;
    unit_count: number;
    restored: boolean;
    created_at: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string; type?: string; priority?: string; warehouse_id?: string | number };
type Props = {
    requests: { data: Row[]; links: LinkData[] };
    filters: Filters;
    summary: { requested: number; preparing: number; shipped: number; customer_open: number };
    warehouses: Warehouse[];
    can: { manage: boolean; receive: boolean };
};

const selectClass = 'h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary focus-visible:ring-2 focus-visible:ring-primary/20';

export default function TransferRequestIndex({ requests, filters, summary, warehouses, can }: Props) {
    const { t } = useI18n();
    const labels = useTransferLabels();
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const replenish = useForm({ warehouse_id: warehouses[0]?.id ?? 0 });

    const current = (): Record<string, string | undefined> => ({
        search: search || undefined,
        status: filters.status || undefined,
        type: filters.type || undefined,
        priority: filters.priority || undefined,
        warehouse_id: filters.warehouse_id ? String(filters.warehouse_id) : undefined,
    });
    const visit = (next: Record<string, string | undefined>) =>
        router.get('/inventory/transfer-requests', { ...current(), ...next, page: undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search: search || undefined }), 280);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const activeFilterCount = ['status', 'type', 'priority', 'warehouse_id'].filter((k) => Boolean((filters as Record<string, unknown>)[k])).length;

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
                        onClick={() => visit(tile.active ? { status: undefined, priority: undefined } : tile.filter)}
                        className={`rounded-card border px-4 py-3 text-start transition-soft focus-visible:outline-2 ${tile.active ? 'border-primary bg-sage' : 'border-line bg-surface hover:border-line-strong'}`}
                    >
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{tile.label}</p>
                        <p className={`mt-1 text-2xl font-semibold tabular-nums ${tile.value > 0 ? tile.tone : 'text-ink-faint'}`}>{tile.value}</p>
                    </button>
                ))}
            </div>

            <section className="mb-4 rounded-card border border-line bg-surface p-4 shadow-soft">
                <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('common.search')}</span>
                <div className="flex gap-2">
                    <div className="min-w-0 flex-1">
                        <SearchInput value={search} onChange={setSearch} searching={searching} placeholder={t('inventory.requests.searchPlaceholder')} />
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        className="shrink-0 sm:hidden"
                        aria-expanded={filtersOpen}
                        aria-controls="transfer-request-filters"
                        onClick={() => setFiltersOpen((open) => !open)}
                    >
                        {t('inventory.trq.filters')}{activeFilterCount > 0 ? ` (${activeFilterCount})` : ''}
                    </Button>
                </div>
                <div id="transfer-request-filters" className={`${filtersOpen ? 'block' : 'hidden'} sm:block`}>
                <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label className="block">
                        <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('common.status')}</span>
                        <select value={filters.status ?? ''} onChange={(e) => visit({ status: e.target.value || undefined })} className={selectClass}>
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="open">{t('inventory.trq.statusOpen')}</option>
                            {Object.entries(labels.status).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('inventory.trq.type')}</span>
                        <select value={filters.type ?? ''} onChange={(e) => visit({ type: e.target.value || undefined })} className={selectClass}>
                            <option value="">{t('inventory.trq.allTypes')}</option>
                            {Object.entries(labels.type).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('inventory.trq.warehouse')}</span>
                        <select value={filters.warehouse_id ? String(filters.warehouse_id) : ''} onChange={(e) => visit({ warehouse_id: e.target.value || undefined })} className={selectClass}>
                            <option value="">{t('inventory.trq.allWarehouses')}</option>
                            {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('inventory.trq.priority')}</span>
                        <select value={filters.priority ?? ''} onChange={(e) => visit({ priority: e.target.value || undefined })} className={selectClass}>
                            <option value="">{t('inventory.trq.allPriorities')}</option>
                            <option value="high">{t('inventory.trq.priorityHigh')}</option>
                            <option value="normal">{t('inventory.trq.priorityNormal')}</option>
                        </select>
                    </label>
                </div>
                {activeFilterCount > 0 && (
                    <div className="mt-3 flex items-center gap-2 text-xs text-ink-muted">
                        <span>{t('inventory.trq.activeFilters').replace('{count}', String(activeFilterCount))}</span>
                        <button type="button" className="font-medium text-primary hover:underline" onClick={() => visit({ status: undefined, type: undefined, priority: undefined, warehouse_id: undefined })}>
                            {t('inventory.trq.clearFilters')}
                        </button>
                    </div>
                )}
                {can.manage && warehouses.length > 0 && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            replenish.post('/inventory/transfer-requests/replenish', { preserveScroll: true });
                        }}
                        className="mt-4 flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:flex-wrap sm:items-end"
                    >
                        <label className="min-w-0 flex-1 sm:min-w-64 sm:max-w-sm">
                            <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('inventory.requests.warehouseEvaluate')}</span>
                            <select value={replenish.data.warehouse_id} onChange={(e) => replenish.setData('warehouse_id', Number(e.target.value))} className={selectClass}>
                                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name} · {w.code}</option>)}
                            </select>
                        </label>
                        <Button type="submit" variant="secondary" loading={replenish.processing} loadingText={t('inventory.requests.evaluating')}>
                            {t('inventory.requests.evaluate')}
                        </Button>
                        <p className="text-xs text-ink-faint sm:basis-full lg:ms-2 lg:basis-auto lg:max-w-xs">{t('inventory.trq.evaluateHelp')}</p>
                    </form>
                )}
                </div>
            </section>

            {requests.data.length === 0 ? (
                <EmptyState
                    title={activeFilterCount > 0 || filters.search ? t('inventory.trq.noMatch') : t('inventory.requests.empty')}
                    description={activeFilterCount > 0 || filters.search ? t('inventory.trq.noMatchHelp') : t('inventory.requests.emptyHelp')}
                />
            ) : (
                <>
                    {/* Desktop / tablet */}
                    <div className="hidden overflow-hidden rounded-card border border-line bg-surface shadow-soft xl:block">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[860px] text-start text-sm">
                                <thead className="border-b border-line bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                    <tr>
                                        <th scope="col" className="px-4 py-2.5 text-start font-semibold">{t('inventory.trq.request')}</th>
                                        <th scope="col" className="px-4 py-2.5 text-start font-semibold">{t('inventory.requests.route')}</th>
                                        <th scope="col" className="px-4 py-2.5 text-start font-semibold">{t('inventory.requests.order')}</th>
                                        <th scope="col" className="px-4 py-2.5 text-end font-semibold">{t('inventory.trq.contents')}</th>
                                        <th scope="col" className="px-4 py-2.5 text-start font-semibold">{t('common.status')}</th>
                                        <th scope="col" className="px-4 py-2.5 text-start font-semibold">{t('inventory.requests.createdAt')}</th>
                                        <th scope="col" aria-label={t('inventory.trq.nextAction')} className="px-4 py-2.5 text-end font-semibold" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {requests.data.map((r) => {
                                        const next = nextAction(r);
                                        return (
                                            <tr key={r.id} className={`align-top transition-soft hover:bg-raised/70 ${r.priority === 'high' && r.status !== 'received' && r.status !== 'cancelled' ? 'shadow-[inset_3px_0_0_var(--color-danger)]' : ''}`}>
                                                <td className="px-4 py-3">
                                                    <Link href={`/inventory/transfer-requests/${r.id}`} className="font-semibold text-ink hover:underline focus-visible:underline">
                                                        <bdi>{r.request_number}</bdi>
                                                    </Link>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                        <TransferTypeBadge type={r.type} />
                                                        {r.priority === 'high' && <PriorityBadge priority="high" />}
                                                        {r.restored && <span className="text-[11px] text-ink-faint">{t('inventory.trq.restoredTag')}</span>}
                                                    </div>
                                                </td>
                                                <td className="max-w-[16rem] px-4 py-3"><RouteLabel source={r.source} destination={r.destination} /></td>
                                                <td className="px-4 py-3">
                                                    {r.sales_order ? (
                                                        <Link href={`/sales/orders/${r.sales_order.id}`} className="font-medium text-ink hover:underline"><bdi>{r.sales_order.order_number}</bdi></Link>
                                                    ) : <span className="text-ink-faint">—</span>}
                                                </td>
                                                <td className="px-4 py-3 text-end">
                                                    <p className="font-semibold tabular-nums text-ink">{t('inventory.trq.unitsValue').replace('{units}', formatQuantity(r.unit_count))}</p>
                                                    <p className="text-xs text-ink-faint">{t('inventory.trq.productsValue').replace('{count}', String(r.product_count))}</p>
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
                        </div>
                    </div>

                    {/* Mobile */}
                    <ul className="grid gap-3 md:grid-cols-2 xl:hidden">
                        {requests.data.map((r) => {
                            const next = nextAction(r);
                            return (
                                <li key={r.id} className={`rounded-card border bg-surface p-4 shadow-soft ${r.priority === 'high' && r.status !== 'received' && r.status !== 'cancelled' ? 'border-danger/35' : 'border-line'}`}>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <Link href={`/inventory/transfer-requests/${r.id}`} className="block truncate text-sm font-semibold text-ink hover:underline"><bdi>{r.request_number}</bdi></Link>
                                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                <TransferTypeBadge type={r.type} />
                                                {r.priority === 'high' && <PriorityBadge priority="high" />}
                                            </div>
                                        </div>
                                        <TransferStatusBadge status={r.status} className="shrink-0" />
                                    </div>
                                    <div className="mt-3 text-[13px]"><RouteLabel source={r.source} destination={r.destination} compact /></div>
                                    <dl className="mt-3 grid grid-cols-3 gap-x-3 gap-y-1.5 text-[13px]">
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('inventory.requests.order')}</dt>
                                            <dd className="truncate">{r.sales_order ? <bdi>{r.sales_order.order_number}</bdi> : '—'}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('inventory.trq.contents')}</dt>
                                            <dd className="tabular-nums">{formatQuantity(r.unit_count)} · {r.product_count} {t('inventory.trq.productsShort')}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('inventory.requests.createdAt')}</dt>
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
                </>
            )}
            <Pagination links={requests.links} />
        </InventoryLayout>
    );
}
