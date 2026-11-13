import SandboxProcurementBadge from '@/components/SandboxProcurementBadge';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type Row = {
    id: number;
    procurement_number: string;
    sales_order: { id: number; order_number: string } | null;
    supplier: { id: number; name: string } | null;
    product: string;
    quantity: string;
    status: string;
    status_label: string;
    supplier_availability_status: string;
    ordered_at: string | null;
    expected_at: string | null;
    received_at: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    procurements: { data: Row[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: { search?: string; status?: string };
    can: { manage: boolean; receive: boolean; suppliers: boolean };
};

const STATUS_ORDER = [
    'pending_supplier',
    'supplier_confirmed',
    'ordered',
    'partially_received',
    'received',
    'completed',
    'cancelled',
    'unavailable',
] as const;

export default function ProcurementIndex({ procurements, filters, can }: Props) {
    const { t } = useI18n();
    const statusLabel = (status: string) => t(`procurement.status.${status}` as TranslationKey);
    // Exactly the params ProcurementController@index validates.
    const f = useQueryFilters('/procurement', filters, { keys: ['search', 'status'] });

    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: statusLabel(filters.status), onRemove: () => f.remove('status') });

    const filtered = f.activeCount > 0;
    const emptyTitle = filtered ? t('filters.noMatch') : t('procurement.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('procurement.description');
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : undefined;
    const pager = <TablePager links={procurements.links} from={procurements.from} to={procurements.to} total={procurements.total} />;
    const receipt = (row: Row) =>
        row.received_at ? `${t('procurement.receivedOn')} ${formatDate(row.received_at)}` : row.expected_at ? formatDate(row.expected_at) : '—';

    return (
        <ProcurementLayout>
            <Head title={t('procurement.title')} />
            <PageHeader
                title={t('procurement.title')}
                description={t('procurement.description')}
                actions={
                    can.suppliers ? (
                        <ButtonLink href="/procurement/suppliers" variant="secondary">
                            {t('procurement.suppliers')}
                        </ButtonLink>
                    ) : undefined
                }
            />
            <SandboxProcurementBadge />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('procurement.title')}
                    search={
                        <FilterSearch
                            value={f.search}
                            onChange={f.setSearch}
                            loading={f.searching}
                            label={t('common.search')}
                            placeholder={t('procurement.searchPlaceholder')}
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
                        options={STATUS_ORDER.map((status) => ({ value: status, label: statusLabel(status) }))}
                        value={filters.status}
                        onChange={(value) => f.set('status', value)}
                    />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('procurement.title')}
                state={procurements.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden md:block"
                footer={procurements.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[900px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t('common.reference')}</th>
                            <th className="px-4 py-3">{t('procurement.customerOrder')}</th>
                            <th className="px-4 py-3">{t('procurement.supplier')}</th>
                            <th className="px-4 py-3">{t('procurement.item')}</th>
                            <th className="num px-4 py-3">{t('inventory.quantity')}</th>
                            <th className="px-4 py-3">{t('common.status')}</th>
                            <th className="px-4 py-3">{t('procurement.orderedOn')}</th>
                            <th className="px-4 py-3">{t('procurement.expectedReceipt')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {procurements.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 font-semibold text-ink">
                                    <bdi>{row.procurement_number}</bdi>
                                </td>
                                <td className="px-4 py-3">
                                    {row.sales_order ? (
                                        <Link href={`/sales/orders/${row.sales_order.id}`} className="font-medium text-ink hover:text-accent hover:underline">
                                            <bdi>{row.sales_order.order_number}</bdi>
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{row.supplier?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-ink-muted">{row.product || '—'}</td>
                                <td className="num px-4 py-3">{formatQuantity(row.quantity)}</td>
                                <td className="px-4 py-3">
                                    <StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge>
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{row.ordered_at ? formatDate(row.ordered_at) : '—'}</td>
                                <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{receipt(row)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards (filter bar above is shared). */}
            {procurements.data.length === 0 ? (
                <div className="md:hidden">
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                </div>
            ) : (
                <div className="space-y-3 md:hidden" aria-busy={f.loading || undefined}>
                    <ul className={`space-y-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {procurements.data.map((row) => (
                            <li key={row.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-ink">
                                            <bdi>{row.procurement_number}</bdi>
                                        </p>
                                        <p className="truncate text-[13px] text-ink-muted">{row.product || '—'}</p>
                                    </div>
                                    <StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('procurement.customerOrder')}</dt>
                                        <dd className="truncate">
                                            {row.sales_order ? (
                                                <Link href={`/sales/orders/${row.sales_order.id}`} className="font-medium text-ink underline">
                                                    <bdi>{row.sales_order.order_number}</bdi>
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('procurement.supplier')}</dt>
                                        <dd className="truncate text-ink-muted">{row.supplier?.name ?? '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('inventory.quantity')}</dt>
                                        <dd className="tabular-nums text-ink-muted">{formatQuantity(row.quantity)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{row.received_at ? t('procurement.receivedOn') : t('procurement.expectedReceipt')}</dt>
                                        <dd className="text-ink-muted">
                                            {row.received_at ? formatDate(row.received_at) : row.expected_at ? formatDate(row.expected_at) : '—'}
                                        </dd>
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
