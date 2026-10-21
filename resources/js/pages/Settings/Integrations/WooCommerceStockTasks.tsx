import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Row = {
    id: number;
    product_name: string | null;
    variant_label: string | null;
    sku: string | null;
    quantity_delta: string;
    source_reference: string | null;
    reason: string;
    status: string;
    status_label: string;
    created_at: string | null;
    completed_at: string | null;
    completed_by: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    tasks: { data: Row[]; links: LinkData[] };
    filters: { search?: string; status: string };
    pendingCount: number;
    can: { manage: boolean };
};

export default function WooCommerceStockTasksIndex({ tasks, filters, pendingCount, can }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const tabs = [
        { value: 'pending', label: t('wooTasks.pending') },
        { value: 'completed', label: t('wooTasks.completed') },
        { value: 'all', label: t('wooTasks.all') },
    ] as const;

    const visit = (next: Record<string, string | undefined>) =>
        router.get('/integrations/woocommerce/stock-tasks', next, {
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

    const complete = (id: number) =>
        router.post(`/integrations/woocommerce/stock-tasks/${id}/complete`, {}, { preserveScroll: true });

    return (
        <ApplicationShell>
            <Head title={t('wooTasks.title')} />
            <PageHeader
                title={t('wooTasks.title')}
                description={t('wooTasks.pendingDescription').replace('{count}', String(pendingCount))}
                actions={
                    <Link
                        href="/integrations/woocommerce"
                        className="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm text-slate-900 hover:bg-slate-50"
                    >
                        {t('wooTasks.integration')}
                    </Link>
                }
            />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <div className="inline-flex rounded-lg border border-slate-300 bg-white p-1">
                    {tabs.map((tab) => (
                        <button
                            key={tab.value}
                            onClick={() => visit({ search: filters.search, status: tab.value, page: undefined })}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                                filters.status === tab.value ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder={t('wooTasks.search')} />
            </div>

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="w-full min-w-[900px] text-start text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3 font-medium">{t('wooTasks.item')}</th>
                            <th className="px-4 py-3 font-medium">SKU</th>
                            <th className="px-4 py-3 text-end font-medium">{t('wooTasks.movement')}</th>
                            <th className="px-4 py-3 font-medium">{t('wooTasks.source')}</th>
                            <th className="px-4 py-3 font-medium">{t('wooTasks.date')}</th>
                            <th className="px-4 py-3 font-medium">{t('common.status')}</th>
                            <th className="px-4 py-3 font-medium">{t('wooTasks.action')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {tasks.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 text-slate-900">
                                    {row.product_name}
                                    {row.variant_label ? <span className="text-slate-500"> · {row.variant_label}</span> : null}
                                </td>
                                <td className="px-4 py-3 text-slate-600"><bdi>{row.sku ?? '—'}</bdi></td>
                                <td className="px-4 py-3 text-end tabular-nums text-slate-900">{formatQuantity(row.quantity_delta)}</td>
                                <td className="px-4 py-3 text-slate-600"><bdi>{row.source_reference ?? '—'}</bdi></td>
                                <td className="px-4 py-3 text-slate-600">{row.created_at ? formatDate(row.created_at) : '—'}</td>
                                <td className="px-4 py-3">
                                    {row.status === 'completed' ? (
                                        <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
                                            {t('wooTasks.completed')}
                                        </span>
                                    ) : row.status === 'cancelled' ? (
                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500">{t('wooTasks.cancelled')}</span>
                                    ) : (
                                        <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">{t('wooTasks.pending')}</span>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    {row.status === 'pending' && can.manage ? (
                                        <button
                                            onClick={() => complete(row.id)}
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-900 hover:bg-slate-50"
                                        >
                                            {t('wooTasks.markCompleted')}
                                        </button>
                                    ) : row.status === 'completed' ? (
                                        <span className="text-xs text-slate-500">
                                            {t('wooTasks.completedBy').replace('{user}', row.completed_by ?? '—')}
                                            {row.completed_at ? ` ${t('wooTasks.completedOn').replace('{date}', formatDate(row.completed_at))}` : ''}
                                        </span>
                                    ) : null}
                                </td>
                            </tr>
                        ))}
                        {tasks.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
                                    {t('wooTasks.empty')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination links={tasks.links} />
        </ApplicationShell>
    );
}
