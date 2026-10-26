import { TeamCounts, type TeamRow } from '@/components/team/TeamCounts';
import WorkNav from '@/components/followups/WorkNav';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import { MetricCard } from '@/components/ui/MetricCard';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

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

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

/** W5 Team: who has what, what is late — operational workload only (no score, no ranking of people). */
export default function WorkTeam({ filters, summary, unassigned, members, stores, currentUserId, canSeeAttention }: Props) {
    const { t } = useI18n();
    const [query, setQuery] = useState(filters.query ?? '');
    const visit = (next: Record<string, unknown>) => router.get('/work/team', { ...filters, ...next, page: undefined }, { preserveState: true, replace: true });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit({ query: query.trim() || undefined });
    };
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

            <form onSubmit={submit} className="mb-4 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('team.searchPlaceholder')} className={`${fieldClass} w-full min-w-0 flex-1 sm:w-auto sm:min-w-60`} />
                {stores.length > 1 && (
                    <select value={String(filters.store_id ?? '')} onChange={(e) => visit({ store_id: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('team.store')}>
                        <option value="">{t('team.allStores')}</option>
                        {stores.map((store) => <option key={store.id} value={store.id}>{store.name}</option>)}
                    </select>
                )}
                <select value={filters.scope ?? 'all'} onChange={(e) => visit({ scope: e.target.value === 'all' ? undefined : e.target.value })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('team.scope')}>
                    <option value="all">{t('team.scopeAll')}</option>
                    <option value="mine">{t('team.scopeMine')}</option>
                    <option value="unassigned">{t('team.scopeUnassigned')}</option>
                </select>
                <select value={filters.signal ?? ''} onChange={(e) => visit({ signal: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('team.signal')}>
                    <option value="">{t('team.signalAll')}</option>
                    <option value="overdue">{t('team.signalOverdue')}</option>
                    <option value="today">{t('team.signalToday')}</option>
                    <option value="upcoming">{t('team.signalUpcoming')}</option>
                    {canSeeAttention && <option value="escalated">{t('team.signalEscalated')}</option>}
                    {canSeeAttention && <option value="critical">{t('team.signalCritical')}</option>}
                    {canSeeAttention && <option value="warning">{t('team.signalWarning')}</option>}
                </select>
                <button type="submit" className="inline-flex min-h-10 flex-1 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised sm:flex-none">{t('common.search')}</button>
            </form>

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
                <ul className="divide-y divide-line rounded-card border border-line bg-surface">
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
