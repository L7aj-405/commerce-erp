import { TeamCounts, type TeamRow } from '@/components/team/TeamCounts';
import WorkNav from '@/components/followups/WorkNav';
import DocBadge from '@/components/ui/DocBadge';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import EmptyState from '@/components/ui/EmptyState';
import { MetricCard } from '@/components/ui/MetricCard';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type LinkData = { url: string | null; label: string; active: boolean };
type Filters = { store_id?: number | string; query?: string; scope?: 'all' | 'mine' | 'unassigned'; signal?: string };
type Props = {
    filters: Filters;
    summary: { overdue: number; today: number; critical: number; escalated: number; unassigned: number };
    unassigned: TeamRow;
    members: { data: TeamRow[]; links: LinkData[]; total: number };
    stores: { id: number; name: string }[];
    currentUserId: number;
    canSeeAttention: boolean;
};


/** W5 Team: who has what, what is late — operational workload only (no score, no ranking of people). */
export default function WorkTeam({ filters, summary, unassigned, members, stores, currentUserId, canSeeAttention }: Props) {
    const { t } = useI18n();
    const [query, setQuery] = useState(filters.query ?? '');
    const visit = (next: Record<string, unknown>) => router.get('/work/team', { ...filters, ...next, page: undefined }, { preserveState: true, replace: true });
    const [filtering, setFiltering] = useState(false);
    // Live search (debounced) on the same `query` param TeamWorkQuery validates.
    useEffect(() => {
        if (query.trim() === (filters.query ?? '')) return;
        const timer = window.setTimeout(
            () =>
                router.get('/work/team', { ...filters, query: query.trim() || undefined, page: undefined }, {
                    preserveState: true,
                    replace: true,
                    onStart: () => setFiltering(true),
                    onFinish: () => setFiltering(false),
                }),
            300,
        );
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);
    const scopeOptions = [
        { value: 'mine', label: t('team.scopeMine') },
        { value: 'unassigned', label: t('team.scopeUnassigned') },
    ];
    const signalOptions = [
        { value: 'overdue', label: t('team.signalOverdue') },
        { value: 'today', label: t('team.signalToday') },
        { value: 'upcoming', label: t('team.signalUpcoming') },
        ...(canSeeAttention
            ? [
                  { value: 'escalated', label: t('team.signalEscalated') },
                  { value: 'critical', label: t('team.signalCritical') },
                  { value: 'warning', label: t('team.signalWarning') },
              ]
            : []),
    ];
    const storeOptions = stores.map((store) => ({ value: String(store.id), label: store.name }));
    const chips: FilterChip[] = [];
    if (filters.query) chips.push({ key: 'query', label: t('filters.search'), value: filters.query, onRemove: () => setQuery('') });
    const optionChip = (key: 'store_id' | 'scope' | 'signal', label: string, options: { value: string; label: string }[]) => {
        const current = filters[key];
        if (current === undefined || current === null || current === '') return;
        chips.push({ key, label, value: options.find((o) => o.value === String(current))?.label ?? String(current), onRemove: () => visit({ [key]: undefined }) });
    };
    if (stores.length > 1) optionChip('store_id', t('team.store'), storeOptions);
    optionChip('scope', t('team.scope'), scopeOptions);
    optionChip('signal', t('team.signal'), signalOptions);
    const memberHref = (id: number | 'unassigned') => `/work/team/${id}${filters.store_id ? `?store_id=${filters.store_id}` : ''}`;
    const signalHref = (signal: string) => `/work/team?${new URLSearchParams({ ...(filters.store_id ? { store_id: String(filters.store_id) } : {}), signal })}`;
    const unassignedTotal = unassigned.open + unassigned.attention;

    return (
        <ApplicationShell>
            <Head title={t('team.title')} />
            <PageHeader title={t('team.title')} description={t('team.description')} />
            <WorkNav current="team" canManageAll />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <MetricCard level="secondary" href={signalHref('overdue')} tone={summary.overdue > 0 ? 'warning' : 'neutral'} label={t('team.summaryOverdue')} value={summary.overdue} />
                <MetricCard level="secondary" href={signalHref('today')} label={t('team.summaryToday')} value={summary.today} />
                {canSeeAttention && (
                    <MetricCard level="secondary" href={signalHref('critical')} tone={summary.critical > 0 ? 'negative' : 'neutral'} label={t('team.summaryCritical')} value={summary.critical}
                            helper={summary.escalated > 0 ? t('team.summaryEscalated').replace('{count}', String(summary.escalated)) : undefined} />
                )}
                <MetricCard level="secondary" href={memberHref('unassigned')} tone={summary.unassigned > 0 ? 'warning' : 'neutral'} label={t('team.summaryUnassigned')} value={summary.unassigned} />
            </div>

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('team.title')}
                    search={<FilterSearch value={query} onChange={setQuery} loading={filtering && query.trim() !== (filters.query ?? '')} label={t('common.search')} placeholder={t('team.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={chips.length}
                    loading={filtering}
                    onReset={() => {
                        setQuery('');
                        visit({ query: undefined, store_id: undefined, scope: undefined, signal: undefined });
                    }}
                >
                    {stores.length > 1 && (
                        <ChoiceFilter label={t('team.store')} allLabel={t('team.allStores')} options={storeOptions} value={filters.store_id ? String(filters.store_id) : undefined} onChange={(value) => visit({ store_id: value })} />
                    )}
                    <ChoiceFilter label={t('team.scope')} allLabel={t('team.scopeAll')} options={scopeOptions} value={filters.scope && filters.scope !== 'all' ? String(filters.scope) : undefined} onChange={(value) => visit({ scope: value })} />
                    <ChoiceFilter label={t('team.signal')} allLabel={t('team.signalAll')} options={signalOptions} value={filters.signal ? String(filters.signal) : undefined} onChange={(value) => visit({ signal: value })} />
                </AdvancedFilterBar>
            </div>

            {filters.scope !== 'mine' && (
                <Link href={memberHref('unassigned')} className="mb-4 flex flex-col gap-2 rounded-card border border-dashed border-line-strong bg-surface p-4 transition-soft hover:border-primary sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        <span className="block text-sm font-semibold text-ink">{t('team.unassignedTitle')}</span>
                        <span className="block text-xs text-ink-muted">{unassignedTotal > 0 ? t('team.unassignedHelp') : t('team.unassignedNone')}</span>
                    </span>
                    <TeamCounts row={unassigned} showAttention={canSeeAttention} />
                </Link>
            )}

            {filters.scope === 'unassigned' ? null : members.data.length === 0 ? (
                <EmptyState title={t('team.empty')} description={t('team.emptyHelp')} />
            ) : (
                <ul className="divide-y divide-line surface-card">
                    {members.data.map((row) => (
                        <li key={row.id}>
                            <Link href={memberHref(row.id)} className="flex flex-col gap-2 p-4 transition-soft hover:bg-raised sm:flex-row sm:items-center sm:justify-between">
                                <span className="flex min-w-0 flex-wrap items-center gap-2">
                                    <span className="truncate text-sm font-semibold text-ink">{row.name}</span>
                                    {row.id === currentUserId && <span className="text-[11px] text-ink-faint">{t('team.you')}</span>}
                                    {!row.active && <DocBadge tone="neutral">{t('team.inactive')}</DocBadge>}
                                </span>
                                <TeamCounts row={row} showAttention={canSeeAttention} />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-3 text-xs text-ink-faint">{t('team.orderHelp')} {t('team.definitionHelp')}</p>
            <Pagination links={members.links} />
        </ApplicationShell>
    );
}
