import { ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import SandboxStockBadge from '@/components/SandboxStockBadge';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDate, formatInteger, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Transfer = {
    id: number;
    transfer_number: string;
    transferred_at: string;
    product_count: number;
    unit_count: string;
    status: string;
    source_warehouse: { name: string; code: string };
    destination_warehouse: { name: string; code: string };
    performed_by: { name: string } | null;
    lines: { product_name: string | null; variant_label: string | null; quantity: string }[];
    /** Requests this transfer completed (empty for a direct transfer). */
    transfer_requests: { id: number; request_number: string; sales_order_id: number | null; sales_order_number: string | null }[];
};

function Origin({ transfer }: { transfer: Transfer }) {
    const { t } = useI18n();
    if (transfer.transfer_requests.length === 0) {
        return <span className="text-xs text-ink-muted">{t('inventory.transfers.direct')}</span>;
    }
    return (
        <span className="flex flex-col gap-0.5">
            {transfer.transfer_requests.map((request) => (
                <span key={request.id} className="flex flex-wrap items-center gap-x-1.5 text-xs">
                    <Link href={`/inventory/transfer-requests/${request.id}`} className="font-medium text-ink hover:underline"><bdi>{request.request_number}</bdi></Link>
                    {request.sales_order_id && request.sales_order_number && (
                        <Link href={`/sales/orders/${request.sales_order_id}`} className="text-ink-muted hover:underline">· <bdi>{request.sales_order_number}</bdi></Link>
                    )}
                </span>
            ))}
        </span>
    );
}

function ProductsSummary({ transfer }: { transfer: Transfer }) {
    return (
        <span className="block max-w-[18rem] truncate text-xs text-ink-faint">
            {transfer.lines.map((line) => `${line.product_name ?? line.variant_label ?? '—'} × ${formatQuantity(line.quantity)}`).join(' · ')}
        </span>
    );
}
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    transfers: { data: Transfer[]; links: LinkData[] };
    filters: { search?: string };
    can: { create: boolean };
};

export default function TransferIndex({ transfers, filters, can }: Props) {
    const { direction, t } = useI18n();
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const visit = (next: Record<string, string | undefined>) => router.get('/inventory/transfers', next, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => setSearching(true),
        onFinish: () => setSearching(false),
    });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search: search || undefined, page: undefined as unknown as string }), 280);

        return () => window.clearTimeout(timer);
    }, [search]);

    return (
        <InventoryLayout>
            <Head title={t('inventory.transfers.title')} />
            <PageHeader
                title={t('inventory.transfers.title')}
                description={t('inventory.transfers.description')}
                actions={can.create ? <ButtonLink href="/inventory/transfers/create"><IconPlus /> {t('inventory.transfers.new')}</ButtonLink> : undefined}
            />
            <SandboxStockBadge />

            <section className="mb-5 rounded-card border border-line bg-surface p-4 shadow-soft">
                <label className="mb-2 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('inventory.transfers.search')}</label>
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder={t('inventory.transfers.searchPlaceholder')} />
            </section>

            {transfers.data.length === 0 ? (
                <EmptyState
                    title={t('inventory.transfers.empty')}
                    description={t('inventory.transfers.emptyHelp')}
                    actions={can.create ? <ButtonLink href="/inventory/transfers/create">{t('inventory.transfers.createFirst')}</ButtonLink> : undefined}
                />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-hidden rounded-card border border-line bg-surface shadow-soft md:block">
                        <div className="flex items-center justify-between border-b border-line px-4 py-3">
                            <div>
                                <h2 className="text-sm font-semibold text-ink">{t('inventory.transfers.history')}</h2>
                                <p className="mt-0.5 text-xs text-ink-muted">{t('inventory.transfers.historyHelp')}</p>
                            </div>
                            <span className="rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">{formatInteger(transfers.data.length)} {t('inventory.transfers.shown')}</span>
                        </div>
                        <div className="overflow-x-auto">
                        <table className="erp-table w-full min-w-[960px] text-start text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-3">{t('common.reference')}</th><th className="px-4 py-3">{t('common.date')}</th>
                                    <th className="px-4 py-3">{t('inventory.transfers.route')}</th>
                                    <th className="px-4 py-3">{t('inventory.transfers.origin')}</th>
                                    <th className="px-4 py-3 text-end">{t('inventory.transfers.products')}</th>
                                    <th className="px-4 py-3 text-end">{t('inventory.transfers.units')}</th>
                                    <th className="px-4 py-3">{t('inventory.transfers.performedBy')}</th><th className="px-4 py-3">{t('common.status')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {transfers.data.map(transfer => (
                                    <tr key={transfer.id} className="transition-soft hover:bg-raised/70">
                                        <td className="px-4 py-3 font-semibold"><Link href={`/inventory/transfers/${transfer.id}`} className="text-ink hover:underline">{transfer.transfer_number}</Link></td>
                                        <td className="px-4 py-3 text-ink-muted">{formatDate(transfer.transferred_at)}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2 font-medium text-ink">
                                                <span>{transfer.source_warehouse.name}</span><IconArrow rtl={direction === 'rtl'} /><span>{transfer.destination_warehouse.name}</span>
                                            </div>
                                            <ProductsSummary transfer={transfer} />
                                        </td>
                                        <td className="px-4 py-3"><Origin transfer={transfer} /></td>
                                        <td className="px-4 py-3 text-end tabular-nums text-ink-muted">{formatInteger(transfer.product_count)}</td>
                                        <td className="px-4 py-3 text-end font-semibold tabular-nums text-ink">{formatQuantity(transfer.unit_count)}</td>
                                        <td className="px-4 py-3 text-ink-muted">{transfer.performed_by?.name ?? t('inventory.system')}</td>
                                        <td className="px-4 py-3"><StatusBadge status="completed">{t('inventory.transfers.completed')}</StatusBadge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        </div>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {transfers.data.map(transfer => (
                            <li key={transfer.id} className="rounded-card border border-line bg-surface p-4 shadow-soft">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/inventory/transfers/${transfer.id}`} className="block truncate text-sm font-semibold hover:underline">
                                            {transfer.transfer_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">
                                            {transfer.source_warehouse.name} → {transfer.destination_warehouse.name}
                                        </p>
                                        <ProductsSummary transfer={transfer} />
                                    </div>
                                    <StatusBadge status="completed">{t('inventory.transfers.completed')}</StatusBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('common.date')}</dt>
                                        <dd>{formatDate(transfer.transferred_at)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('inventory.transfers.performedBy')}</dt>
                                        <dd className="truncate">{transfer.performed_by?.name ?? t('inventory.system')}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('inventory.transfers.products')}</dt>
                                        <dd>{formatInteger(transfer.product_count)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('inventory.transfers.units')}</dt>
                                        <dd className="tabular-nums">{formatQuantity(transfer.unit_count)}</dd>
                                    </div>
                                    <div className="col-span-2 min-w-0">
                                        <dt className="text-ink-faint">{t('inventory.transfers.origin')}</dt>
                                        <dd><Origin transfer={transfer} /></dd>
                                    </div>
                                </dl>
                                <Link
                                    href={`/inventory/transfers/${transfer.id}`}
                                    className="mt-4 inline-flex min-h-9 items-center justify-center rounded-field border border-line-strong px-3 text-[13px] font-medium text-ink transition-soft hover:bg-sage"
                                >
                                    {t('common.view')}
                                </Link>
                            </li>
                        ))}
                    </ul>

                    <Pagination links={transfers.links} />
                </>
            )}
        </InventoryLayout>
    );
}

function IconPlus() { return <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 5v14M5 12h14" /></svg>; }
function IconArrow({ rtl }: { rtl: boolean }) { return <svg className={`shrink-0 text-ink-faint ${rtl ? 'rotate-180' : ''}`} width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M5 12h14m-5-5 5 5-5 5" /></svg>; }
