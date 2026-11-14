import { AdvancedFilterBar, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import { Badge } from '@/components/ui/StatusBadge';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, router } from '@inertiajs/react';

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
    tasks: { data: Row[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: { search?: string; status: string };
    pendingCount: number;
    can: { manage: boolean };
};

export default function WooCommerceStockTasksIndex({ tasks, filters, pendingCount, can }: Props) {
    const { t } = useI18n();
    // WooCommerceStockTaskController@index validates `search` and `status`
    // (pending|completed|cancelled|all); the server defaults to `pending`.
    const f = useQueryFilters('/integrations/woocommerce/stock-tasks', filters, { keys: ['search', 'status'] });
    const tabs = [
        { value: 'pending', label: t('wooTasks.pending') },
        { value: 'completed', label: t('wooTasks.completed') },
        { value: 'all', label: t('wooTasks.all') },
    ] as const;
    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status !== 'pending') {
        chips.push({
            key: 'status',
            label: t('common.status'),
            value: tabs.find((tab) => tab.value === filters.status)?.label ?? (filters.status === 'cancelled' ? t('wooTasks.cancelled') : filters.status),
            onRemove: () => f.remove('status'),
        });
    }
    const filtered = chips.length > 0;
    const complete = (id: number) =>
        router.post(`/integrations/woocommerce/stock-tasks/${id}/complete`, {}, { preserveScroll: true });

    return (
        <ApplicationShell>
            <Head title={t('wooTasks.title')} />
            <PageHeader
                title={t('wooTasks.title')}
                description={t('wooTasks.pendingDescription').replace('{count}', String(pendingCount))}
                actions={
                    <ButtonLink href="/integrations/woocommerce" variant="secondary">
                        {t('wooTasks.integration')}
                    </ButtonLink>
                }
            />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('wooTasks.title')}
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('wooTasks.search')} />}
                    chips={chips}
                    activeCount={chips.length}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <div role="group" aria-label={t('common.status')} className="inline-flex h-10 items-center gap-0.5 rounded-field border border-line-strong bg-surface p-0.5">
                        {tabs.map((tab) => {
                            const active = filters.status === tab.value;
                            return (
                                <button
                                    key={tab.value}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => (tab.value === 'pending' ? f.remove('status') : f.set('status', tab.value))}
                                    className={`h-full rounded-[calc(var(--radius-field)-3px)] px-3 text-sm font-medium transition-soft focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary ${
                                        active ? 'bg-primary text-primary-fg' : 'text-ink-muted hover:bg-sage hover:text-ink'
                                    }`}
                                >
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('wooTasks.title')}
                state={tasks.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={filtered ? t('filters.noMatch') : t('wooTasks.empty')}
                emptyDescription={filtered ? t('filters.noMatchHelp') : undefined}
                emptyAction={
                    filtered ? (
                        <Button variant="secondary" onClick={f.reset}>
                            {t('filters.reset')}
                        </Button>
                    ) : undefined
                }
                footer={tasks.data.length === 0 ? undefined : <TablePager links={tasks.links} from={tasks.from} to={tasks.to} total={tasks.total} />}
            >
                <table className="erp-table min-w-[900px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3 font-medium">{t('wooTasks.item')}</th>
                            <th className="px-4 py-3 font-medium">SKU</th>
                            <th className="num px-4 py-3 font-medium">{t('wooTasks.movement')}</th>
                            <th className="px-4 py-3 font-medium">{t('wooTasks.source')}</th>
                            <th className="px-4 py-3 font-medium">{t('wooTasks.date')}</th>
                            <th className="px-4 py-3 font-medium">{t('common.status')}</th>
                            <th className="px-4 py-3 font-medium">{t('wooTasks.action')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {tasks.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 text-ink">
                                    {row.product_name}
                                    {row.variant_label ? <span className="text-ink-muted"> · {row.variant_label}</span> : null}
                                </td>
                                <td className="px-4 py-3 text-ink-muted"><bdi>{row.sku ?? '—'}</bdi></td>
                                <td className="num px-4 py-3 text-ink" dir="ltr">{formatQuantity(row.quantity_delta)}</td>
                                <td className="px-4 py-3 text-ink-muted"><bdi>{row.source_reference ?? '—'}</bdi></td>
                                <td className="px-4 py-3 text-ink-muted">{row.created_at ? formatDate(row.created_at) : '—'}</td>
                                <td className="px-4 py-3">
                                    {row.status === 'completed' ? (
                                        <Badge variant="success">{t('wooTasks.completed')}</Badge>
                                    ) : row.status === 'cancelled' ? (
                                        <Badge variant="neutral">{t('wooTasks.cancelled')}</Badge>
                                    ) : (
                                        <Badge variant="warning">{t('wooTasks.pending')}</Badge>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    {row.status === 'pending' && can.manage ? (
                                        <Button type="button" size="sm" variant="secondary" onClick={() => complete(row.id)}>
                                            {t('wooTasks.markCompleted')}
                                        </Button>
                                    ) : row.status === 'completed' ? (
                                        <span className="text-xs text-ink-muted">
                                            {t('wooTasks.completedBy').replace('{user}', row.completed_by ?? '—')}
                                            {row.completed_at ? ` ${t('wooTasks.completedOn').replace('{date}', formatDate(row.completed_at))}` : ''}
                                        </span>
                                    ) : null}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>
        </ApplicationShell>
    );
}
