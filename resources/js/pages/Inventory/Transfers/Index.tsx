import SandboxStockBadge from '@/components/SandboxStockBadge';
import { AdvancedFilterBar, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { rowLink, TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDate, formatInteger, formatQuantity } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

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
    lines: {
        product_name: string | null;
        variant_label: string | null;
        quantity: string;
    }[];
    /** Requests this transfer completed (empty for a direct transfer). */
    transfer_requests: {
        id: number;
        request_number: string;
        sales_order_id: number | null;
        sales_order_number: string | null;
    }[];
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
                    <Link href={`/inventory/transfer-requests/${request.id}`} className="font-medium text-ink hover:underline">
                        <bdi>{request.request_number}</bdi>
                    </Link>
                    {request.sales_order_id && request.sales_order_number && (
                        <Link href={`/sales/orders/${request.sales_order_id}`} className="text-ink-muted hover:underline">
                            · <bdi>{request.sales_order_number}</bdi>
                        </Link>
                    )}
                </span>
            ))}
        </span>
    );
}

function ProductsSummary({ transfer }: { transfer: Transfer }) {
    return (
        <span className="block max-w-[18rem] truncate text-xs text-ink-muted">
            {transfer.lines.map((line) => `${line.product_name ?? line.variant_label ?? '—'} × ${formatQuantity(line.quantity)}`).join(' · ')}
        </span>
    );
}
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    transfers: {
        data: Transfer[];
        links: LinkData[];
        from?: number | null;
        to?: number | null;
        total?: number;
    };
    filters: { search?: string };
    can: { create: boolean };
};

export default function TransferIndex({ transfers, filters, can }: Props) {
    const { direction, t } = useI18n();
    // Exactly the params StockTransferController@index validates.
    const f = useQueryFilters('/inventory/transfers', filters, {
        keys: ['search'],
    });
    const chips: FilterChip[] = filters.search
        ? [
              {
                  key: 'search',
                  label: t('filters.search'),
                  value: filters.search,
                  onRemove: () => f.remove('search'),
              },
          ]
        : [];
    const filtered = f.activeCount > 0;
    const emptyTitle = filtered ? t('filters.noMatch') : t('inventory.transfers.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('inventory.transfers.emptyHelp');
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : can.create ? (
        <ButtonLink href="/inventory/transfers/create">{t('inventory.transfers.createFirst')}</ButtonLink>
    ) : undefined;
    const pager = <TablePager links={transfers.links} from={transfers.from} to={transfers.to} total={transfers.total} />;

    return (
        <InventoryLayout>
            <Head title={t('inventory.transfers.title')} />
            <PageHeader
                title={t('inventory.transfers.title')}
                description={t('inventory.transfers.description')}
                actions={
                    can.create ? (
                        <ButtonLink href="/inventory/transfers/create">
                            <IconPlus /> {t('inventory.transfers.new')}
                        </ButtonLink>
                    ) : undefined
                }
            />
            <SandboxStockBadge />

            <div className="mb-4">
                <AdvancedFilterBar
                    search={
                        <FilterSearch
                            value={f.search}
                            onChange={f.setSearch}
                            loading={f.searching}
                            label={t('inventory.transfers.search')}
                            placeholder={t('inventory.transfers.searchPlaceholder')}
                        />
                    }
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                />
            </div>

            <TableShell
                label={t('inventory.transfers.history')}
                state={transfers.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden lg:block"
                footer={transfers.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[960px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t('common.reference')}</th>
                            <th className="px-4 py-3">{t('common.date')}</th>
                            <th className="px-4 py-3">{t('inventory.transfers.route')}</th>
                            <th className="px-4 py-3">{t('inventory.transfers.origin')}</th>
                            <th className="num px-4 py-3">{t('inventory.transfers.products')}</th>
                            <th className="num px-4 py-3">{t('inventory.transfers.units')}</th>
                            <th className="px-4 py-3">{t('inventory.transfers.performedBy')}</th>
                            <th className="px-4 py-3">{t('common.status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {transfers.data.map((transfer) => {
                            const row = rowLink(`/inventory/transfers/${transfer.id}`);
                            return (
                                <tr key={transfer.id} onClick={row.onClick} className={row.className}>
                                    <td className="px-4 py-3 font-semibold">
                                        <Link href={`/inventory/transfers/${transfer.id}`} className="text-ink hover:text-accent hover:underline">
                                            <bdi>{transfer.transfer_number}</bdi>
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{formatDate(transfer.transferred_at)}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2 font-medium text-ink">
                                            <span>{transfer.source_warehouse.name}</span>
                                            <IconArrow rtl={direction === 'rtl'} />
                                            <span>{transfer.destination_warehouse.name}</span>
                                        </div>
                                        <ProductsSummary transfer={transfer} />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Origin transfer={transfer} />
                                    </td>
                                    <td className="num px-4 py-3 text-ink-muted">{formatInteger(transfer.product_count)}</td>
                                    <td className="num px-4 py-3 font-semibold text-ink">{formatQuantity(transfer.unit_count)}</td>
                                    <td className="px-4 py-3 text-ink-muted">{transfer.performed_by?.name ?? t('inventory.system')}</td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status="completed">{t('inventory.transfers.completed')}</StatusBadge>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile/tablet: stacked cards (filter bar above is shared). */}
            {transfers.data.length === 0 ? (
                <div className="lg:hidden">
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                </div>
            ) : (
                <div className="lg:hidden" aria-busy={f.loading || undefined}>
                    <ul className={`grid gap-3 transition-opacity sm:grid-cols-2 ${f.loading ? 'opacity-55' : ''}`}>
                        {transfers.data.map((transfer) => (
                            <li key={transfer.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link
                                            href={`/inventory/transfers/${transfer.id}`}
                                            className="block truncate text-base font-semibold text-ink hover:underline"
                                        >
                                            <bdi>{transfer.transfer_number}</bdi>
                                        </Link>
                                        <p className="flex min-w-0 items-center gap-1.5 text-[13px] text-ink-muted">
                                            <span className="truncate">{transfer.source_warehouse.name}</span>
                                            <IconArrow rtl={direction === 'rtl'} />
                                            <span className="truncate">{transfer.destination_warehouse.name}</span>
                                        </p>
                                        <ProductsSummary transfer={transfer} />
                                    </div>
                                    <StatusBadge status="completed">{t('inventory.transfers.completed')}</StatusBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('common.date')}</dt>
                                        <dd>{formatDate(transfer.transferred_at)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('inventory.transfers.performedBy')}</dt>
                                        <dd className="truncate">{transfer.performed_by?.name ?? t('inventory.system')}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('inventory.transfers.products')}</dt>
                                        <dd>{formatInteger(transfer.product_count)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('inventory.transfers.units')}</dt>
                                        <dd className="tabular-nums">{formatQuantity(transfer.unit_count)}</dd>
                                    </div>
                                    <div className="col-span-2 min-w-0">
                                        <dt className="text-ink-muted">{t('inventory.transfers.origin')}</dt>
                                        <dd>
                                            <Origin transfer={transfer} />
                                        </dd>
                                    </div>
                                </dl>
                                <ButtonLink href={`/inventory/transfers/${transfer.id}`} size="sm" variant="outline" className="mt-3 w-full">
                                    {t('common.view')}
                                    <span className="sr-only"> {transfer.transfer_number}</span>
                                </ButtonLink>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-4">{pager}</div>
                </div>
            )}
        </InventoryLayout>
    );
}

function IconPlus() {
    return (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M12 5v14M5 12h14" />
        </svg>
    );
}
function IconArrow({ rtl }: { rtl: boolean }) {
    return (
        <svg
            className={`shrink-0 text-ink-faint ${rtl ? 'rotate-180' : ''}`}
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
        >
            <path d="M5 12h14m-5-5 5 5-5 5" />
        </svg>
    );
}
