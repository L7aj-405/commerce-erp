import AttentionList from '@/components/attention/AttentionList';
import type { AttentionItem, AttentionOwner } from '@/components/attention/types';
import FollowUpList from '@/components/followups/FollowUpList';
import WorkNav from '@/components/followups/WorkNav';
import type { FollowUpContext, FollowUpItem } from '@/components/followups/types';
import { TeamCounts, type TeamRow } from '@/components/team/TeamCounts';
import DocBadge from '@/components/ui/DocBadge';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';

type Tab = 'overdue' | 'today' | 'upcoming' | 'completed' | 'all';
type LinkData = { url: string | null; label: string; active: boolean };
type Filters = { store_id?: number | string; priority?: string; date_from?: string; date_to?: string; severity?: string; escalated?: string | boolean };
type Props = {
    member: TeamRow;
    tab: Tab;
    filters: Filters;
    followUps: { data: FollowUpItem[]; links: LinkData[]; total: number };
    /** null when the viewer cannot see attention at all. */
    attention: { data: AttentionItem[]; total: number } | null;
    context: FollowUpContext;
    owners: AttentionOwner[];
    stores: { id: number; name: string }[];
    completedDays: number;
};

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

/** W5 one member's operational work (or Unassigned work), through the canonical follow-up and attention lists and actions. */
export default function WorkTeamMember({ member, tab, filters, followUps, attention, context, owners, stores, completedDays }: Props) {
    const { t } = useI18n();
    const key = member.id === 0 ? 'unassigned' : String(member.id);
    const visit = (next: Record<string, unknown>) => router.get(`/work/team/${key}`, { tab, ...filters, ...next, page: undefined }, { preserveState: true, replace: true });
    const title = member.id === 0 ? t('team.unassignedTitle') : member.name;

    const tabs: { key: Tab; label: string; count?: number }[] = [
        { key: 'overdue', label: t('work.tabOverdue'), count: member.overdue },
        { key: 'today', label: t('work.tabToday'), count: member.today },
        { key: 'upcoming', label: t('work.tabUpcoming'), count: member.upcoming },
        { key: 'completed', label: t('work.tabCompleted') },
        { key: 'all', label: t('work.tabAll') },
    ];

    return (
        <ApplicationShell>
            <Head title={title} />
            <PageHeader
                title={<span className="flex flex-wrap items-center gap-2">{title}{!member.active && <DocBadge tone="neutral">{t('team.inactive')}</DocBadge>}</span>}
                description={member.id === 0 ? t('team.unassignedHelp') : t('team.memberDescription')}
                actions={<Link href={`/work/team${filters.store_id ? `?store_id=${filters.store_id}` : ''}`} className="inline-flex min-h-10 items-center rounded-field px-3 text-sm font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink">{t('team.backToTeam')}</Link>}
            />
            <WorkNav current="team" canManageAll />

            <div className="mb-4 rounded-card border border-line bg-raised p-4">
                <TeamCounts row={member} showAttention={attention !== null} />
                <p className="mt-2 text-xs text-ink-faint">{t('team.completedWindow').replace('{days}', String(completedDays))}</p>
            </div>

            <div className="mb-4 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                {stores.length > 1 && (
                    <select value={String(filters.store_id ?? '')} onChange={(e) => visit({ store_id: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('team.store')}>
                        <option value="">{t('team.allStores')}</option>
                        {stores.map((store) => <option key={store.id} value={store.id}>{store.name}</option>)}
                    </select>
                )}
                <select value={filters.priority ?? ''} onChange={(e) => visit({ priority: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('team.priority')}>
                    <option value="">{t('team.allPriorities')}</option>
                    <option value="high">{t('work.priorityHigh')}</option>
                    <option value="normal">{t('work.priorityNormal')}</option>
                    <option value="low">{t('work.priorityLow')}</option>
                </select>
                <label className="flex items-center gap-2 text-sm text-ink-muted">
                    {t('team.dateFrom')}
                    <input type="date" value={filters.date_from ?? ''} onChange={(e) => visit({ date_from: e.target.value || undefined })} className={fieldClass} />
                </label>
                <label className="flex items-center gap-2 text-sm text-ink-muted">
                    {t('team.dateTo')}
                    <input type="date" value={filters.date_to ?? ''} min={filters.date_from} onChange={(e) => visit({ date_to: e.target.value || undefined })} className={fieldClass} />
                </label>
                {attention !== null && (
                    <>
                        <select value={filters.severity ?? ''} onChange={(e) => visit({ severity: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('attention.allSeverities')}>
                            <option value="">{t('attention.allSeverities')}</option>
                            <option value="critical">{t('attention.severityCritical')}</option>
                            <option value="warning">{t('attention.severityWarning')}</option>
                        </select>
                        <label className="flex items-center gap-2 text-sm text-ink-muted">
                            <input type="checkbox" checked={Boolean(filters.escalated) && filters.escalated !== '0'} onChange={(e) => visit({ escalated: e.target.checked ? 1 : undefined })} />
                            {t('attention.escalatedOnly')}
                        </label>
                    </>
                )}
            </div>

            {attention !== null && (
                <section className="mb-6">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-ink">{t('team.memberAttention')}</h2>
                        {attention.total > attention.data.length && (
                            <Link href={`/work/attention?assigned=${key}`} className="text-xs font-medium text-primary hover:underline">{t('team.attentionMore').replace('{count}', String(attention.total))}</Link>
                        )}
                    </div>
                    {attention.data.length === 0
                        ? <p className="rounded-field border border-dashed border-line px-3 py-4 text-center text-sm text-ink-muted">{t('team.noAttention')}</p>
                        : <AttentionList items={attention.data} context={context} owners={owners} />}
                </section>
            )}

            <section>
                <h2 className="mb-2 text-sm font-semibold text-ink">{t('team.memberFollowUps')}</h2>
                <nav className="mb-4 flex gap-1 overflow-x-auto border-b border-line" aria-label={t('team.memberFollowUps')}>
                    {tabs.map((item) => (
                        <button key={item.key} type="button" onClick={() => visit({ tab: item.key })} aria-current={tab === item.key ? 'page' : undefined}
                            className={`-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition-soft ${tab === item.key ? 'border-primary text-ink' : 'border-transparent text-ink-muted hover:text-ink'}`}>
                            {item.label}
                            {item.count !== undefined && item.count > 0 && (
                                <span className={`rounded-full px-1.5 text-[11px] ${item.key === 'overdue' ? 'bg-warning-soft text-warning' : 'bg-sage text-ink-muted'}`}>{item.count}</span>
                            )}
                        </button>
                    ))}
                </nav>
                {followUps.data.length === 0
                    ? <p className="rounded-field border border-dashed border-line px-3 py-4 text-center text-sm text-ink-muted">{t('team.noFollowUps')}</p>
                    : <FollowUpList items={followUps.data} context={context} showAssignee />}
                <Pagination links={followUps.links} />
            </section>
        </ApplicationShell>
    );
}
