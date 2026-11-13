import SandboxProcurementBadge from '@/components/SandboxProcurementBadge';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { rowLink, TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { formatDate, formatInteger } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type Row = {
    id: number;
    purchase_order_number: string;
    status: string;
    supplier: { id: number; name: string } | null;
    store: { id: number; name: string } | null;
    order_date: string | null;
    expected_delivery_date: string | null;
    line_count: number;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    purchaseOrders: { data: Row[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: { search?: string; status?: string };
};

const STATUSES = ['ordered', 'partially_received', 'received', 'draft', 'cancelled'] as const;

export default function PurchaseOrdersIndex({ purchaseOrders, filters }: Props) {
    const { t } = useI18n();
    const statusLabel = (status: string) => t(`purchaseOrders.status.${status}` as TranslationKey);
    // Exactly the params PurchaseOrderController@index validates.
    const f = useQueryFilters('/procurement/purchase-orders', filters, { keys: ['search', 'status'] });

    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: statusLabel(filters.status), onRemove: () => f.remove('status') });

    const filtered = f.activeCount > 0;
    const emptyTitle = filtered ? t('filters.noMatch') : t('purchaseOrders.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('purchaseOrders.description');
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : undefined;
    const pager = <TablePager links={purchaseOrders.links} from={purchaseOrders.from} to={purchaseOrders.to} total={purchaseOrders.total} />;

    return (
        <ProcurementLayout>
            <Head title={t('purchaseOrders.title')} />
            <PageHeader title={t('purchaseOrders.title')} description={t('purchaseOrders.description')} />
            <SandboxProcurementBadge />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('purchaseOrders.title')}
                    search={
                        <FilterSearch
                            value={f.search}
                            onChange={f.setSearch}
                            loading={f.searching}
                            label={t('common.search')}
                            placeholder={t('purchaseOrders.searchPlaceholder')}
                        />
                    }
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter
                        label={t('common.status')}
                        allLabel={t('common.allStatuses')}
                        options={STATUSES.map((status) => ({ value: status, label: statusLabel(status) }))}
                        value={filters.status}
                        onChange={(value) => f.set('status', value)}
                    />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('purchaseOrders.title')}
                state={purchaseOrders.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden md:block"
                footer={purchaseOrders.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[760px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t('common.reference')}</th>
                            <th className="px-4 py-3">{t('purchaseOrders.supplier')}</th>
                            <th className="px-4 py-3">{t('purchaseOrders.store')}</th>
                            <th className="px-4 py-3">{t('purchaseOrders.orderDate')}</th>
                            <th className="px-4 py-3">{t('purchaseOrders.expectedDelivery')}</th>
                            <th className="num px-4 py-3">{t('purchaseOrders.lineCount')}</th>
                            <th className="px-4 py-3">{t('common.status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {purchaseOrders.data.map((row) => {
                            const link = rowLink(`/procurement/purchase-orders/${row.id}`);
                            return (
                                <tr key={row.id} onClick={link.onClick} className={link.className}>
                                    <td className="px-4 py-3 font-semibold">
                                        <Link href={`/procurement/purchase-orders/${row.id}`} className="text-ink hover:text-accent hover:underline">
                                            <bdi>{row.purchase_order_number}</bdi>
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-ink-muted">{row.supplier?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-ink-muted">{row.store?.name ?? '—'}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{row.order_date ? formatDate(row.order_date) : '—'}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">
                                        {row.expected_delivery_date ? formatDate(row.expected_delivery_date) : '—'}
                                    </td>
                                    <td className="num px-4 py-3">{formatInteger(row.line_count)}</td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {purchaseOrders.data.length === 0 ? (
                <div className="md:hidden">
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                </div>
            ) : (
                <div className="space-y-3 md:hidden" aria-busy={f.loading || undefined}>
                    <ul className={`space-y-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {purchaseOrders.data.map((row) => (
                            <li key={row.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/procurement/purchase-orders/${row.id}`} className="block truncate text-sm font-semibold text-ink hover:underline">
                                            <bdi>{row.purchase_order_number}</bdi>
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">{row.supplier?.name ?? '—'}</p>
                                    </div>
                                    <StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('purchaseOrders.orderDate')}</dt>
                                        <dd className="text-ink-muted">{row.order_date ? formatDate(row.order_date) : '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('purchaseOrders.expectedDelivery')}</dt>
                                        <dd className="text-ink-muted">{row.expected_delivery_date ? formatDate(row.expected_delivery_date) : '—'}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                    {pager}
                </div>
            )}
        </ProcurementLayout>
    );
}
