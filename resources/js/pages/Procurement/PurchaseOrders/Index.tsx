import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { formatDate } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
    purchaseOrders: { data: Row[]; links: LinkData[] };
    filters: { search?: string; status?: string };
};

const STATUSES = ['ordered', 'draft', 'cancelled'] as const;

export default function PurchaseOrdersIndex({ purchaseOrders, filters }: Props) {
    const { t } = useI18n();
    const statusLabel = (status: string) => t(`purchaseOrders.status.${status}` as TranslationKey);
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);

    const visit = (next: Record<string, string | undefined>) =>
        router.get('/procurement/purchase-orders', next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search: search || undefined, status: filters.status, page: undefined }), 280);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <ProcurementLayout>
            <Head title={t('purchaseOrders.title')} />
            <PageHeader title={t('purchaseOrders.title')} description={t('purchaseOrders.description')} />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder={t('purchaseOrders.searchPlaceholder')} />
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => visit({ search: filters.search, status: e.target.value || undefined, page: undefined })}
                    className="control-select"
                >
                    <option value="">{t('common.allStatuses')}</option>
                    {STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {statusLabel(s)}
                        </option>
                    ))}
                </select>
            </div>

            {purchaseOrders.data.length === 0 ? (
                <EmptyState title={t('purchaseOrders.empty')} description={t('purchaseOrders.description')} />
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="erp-table w-full min-w-[760px] text-start text-sm">
                            <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-medium">N°</th>
                                    <th className="px-4 py-3 font-medium">{t('purchaseOrders.supplier')}</th>
                                    <th className="px-4 py-3 font-medium">{t('purchaseOrders.store')}</th>
                                    <th className="px-4 py-3 font-medium">{t('purchaseOrders.orderDate')}</th>
                                    <th className="px-4 py-3 font-medium">{t('purchaseOrders.expectedDelivery')}</th>
                                    <th className="px-4 py-3 text-end font-medium">{t('purchaseOrders.lineCount')}</th>
                                    <th className="px-4 py-3 font-medium">{t('common.status')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {purchaseOrders.data.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-4 py-3 font-medium">
                                            <Link href={`/procurement/purchase-orders/${row.id}`} className="text-ink underline">
                                                <bdi>{row.purchase_order_number}</bdi>
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">{row.supplier?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-ink-muted">{row.store?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-ink-muted">{row.order_date ? formatDate(row.order_date) : '—'}</td>
                                        <td className="px-4 py-3 text-ink-muted">{row.expected_delivery_date ? formatDate(row.expected_delivery_date) : '—'}</td>
                                        <td className="px-4 py-3 text-end tabular-nums">{row.line_count}</td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden">
                        {purchaseOrders.data.map((row) => (
                            <li key={row.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/procurement/purchase-orders/${row.id}`} className="block truncate text-sm font-semibold text-ink underline">
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
                </>
            )}

            <Pagination links={purchaseOrders.links} />
        </ProcurementLayout>
    );
}
