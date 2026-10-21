import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import EmptyState from '@/components/ui/EmptyState';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
    procurements: { data: Row[]; links: LinkData[] };
    filters: { search?: string; status?: string };
    can: { manage: boolean; receive: boolean; suppliers: boolean };
};

const STATUS_ORDER = [
    'pending_supplier',
    'supplier_confirmed',
    'ordered',
    'received',
    'completed',
    'cancelled',
    'unavailable',
] as const;
export default function ProcurementIndex({ procurements, filters, can }: Props) {
    const { t } = useI18n();
    const statusLabel = (status: string) => t(`procurement.status.${status}` as TranslationKey);
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);

    const visit = (next: Record<string, string | undefined>) =>
        router.get('/procurement', next, {
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
            <Head title={t('procurement.title')} />
            <PageHeader
                title={t('procurement.title')}
                description={t('procurement.description')}
                actions={
                    can.suppliers ? (
                        <Link
                            href="/procurement/suppliers"
                            className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                        >
                            {t('procurement.suppliers')}
                        </Link>
                    ) : undefined
                }
            />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder={t('procurement.searchPlaceholder')} />
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => visit({ search: filters.search, status: e.target.value || undefined, page: undefined })}
                    className="control-select"
                >
                    <option value="">{t('common.allStatuses')}</option>
                    {STATUS_ORDER.map((s) => (
                        <option key={s} value={s}>
                            {statusLabel(s)}
                        </option>
                    ))}
                </select>
            </div>

            {procurements.data.length === 0 ? (
                <EmptyState title={t('procurement.empty')} description={t('procurement.description')} />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="erp-table w-full min-w-[900px] text-start text-sm">
                            <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-medium">N°</th>
                                    <th className="px-4 py-3 font-medium">{t('procurement.customerOrder')}</th>
                                    <th className="px-4 py-3 font-medium">{t('procurement.supplier')}</th><th className="px-4 py-3 font-medium">{t('procurement.item')}</th>
                                    <th className="px-4 py-3 text-end font-medium">{t('inventory.quantity')}</th><th className="px-4 py-3 font-medium">{t('common.status')}</th>
                                    <th className="px-4 py-3 font-medium">{t('procurement.orderedOn')}</th><th className="px-4 py-3 font-medium">{t('procurement.expectedReceipt')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {procurements.data.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-4 py-3 font-medium text-slate-900">{row.procurement_number}</td>
                                        <td className="px-4 py-3">
                                            {row.sales_order ? (
                                                <Link href={`/sales/orders/${row.sales_order.id}`} className="text-slate-900 underline">
                                                    {row.sales_order.order_number}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">{row.supplier?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-slate-600">{row.product || '—'}</td>
                                        <td className="px-4 py-3 text-end tabular-nums">{formatQuantity(row.quantity)}</td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge>
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">{row.ordered_at ? formatDate(row.ordered_at) : '—'}</td>
                                        <td className="px-4 py-3 text-slate-600">
                                            {row.received_at
                                                ? `${t('procurement.receivedOn')} ${formatDate(row.received_at)}`
                                                : row.expected_at
                                                  ? formatDate(row.expected_at)
                                                  : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {procurements.data.map((row) => (
                            <li key={row.id} className="rounded-xl border border-slate-200 bg-white p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-slate-900">{row.procurement_number}</p>
                                        <p className="truncate text-[13px] text-slate-500">{row.product || '—'}</p>
                                    </div>
                                    <StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('procurement.customerOrder')}</dt>
                                        <dd className="truncate">
                                            {row.sales_order ? (
                                                <Link href={`/sales/orders/${row.sales_order.id}`} className="text-slate-900 underline">
                                                    {row.sales_order.order_number}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('procurement.supplier')}</dt>
                                        <dd className="truncate text-slate-600">{row.supplier?.name ?? '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('inventory.quantity')}</dt>
                                        <dd className="tabular-nums text-slate-600">{formatQuantity(row.quantity)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{row.received_at ? t('procurement.receivedOn') : t('procurement.expectedReceipt')}</dt>
                                        <dd className="text-slate-600">
                                            {row.received_at
                                                ? formatDate(row.received_at)
                                                : row.expected_at
                                                  ? formatDate(row.expected_at)
                                                  : '—'}
                                        </dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={procurements.links} />
        </ProcurementLayout>
    );
}
