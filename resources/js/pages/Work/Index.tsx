import FollowUpFormDialog from '@/components/followups/FollowUpFormDialog';
import FollowUpList from '@/components/followups/FollowUpList';
import WorkNav from '@/components/followups/WorkNav';
import type { FollowUpContext, FollowUpItem } from '@/components/followups/types';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { TablePager } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Tab = 'today' | 'overdue' | 'upcoming' | 'completed' | 'all';
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    tab: Tab;
    filters: { assigned_to?: string; query?: string; customer_id?: number; quotation_id?: number; date_from?: string; date_to?: string; focus?: number };
    followUps: { data: FollowUpItem[]; links: LinkData[]; total: number };
    counts: { today: number; overdue: number; upcoming: number };
    context: FollowUpContext;
};


export default function WorkIndex({ tab, filters, followUps, counts, context }: Props) {
    const { t } = useI18n();
    const [creating, setCreating] = useState(false);
    const [query, setQuery] = useState(filters.query ?? '');
    const visit = (next: Record<string, unknown>) => router.get('/work', { tab, ...filters, ...next, focus: undefined, page: undefined }, { preserveState: true, replace: true });

    const tabs: { key: Tab; label: string; count?: number }[] = [
        { key: 'today', label: t('work.tabToday'), count: counts.today },
        { key: 'overdue', label: t('work.tabOverdue'), count: counts.overdue },
        { key: 'upcoming', label: t('work.tabUpcoming'), count: counts.upcoming },
        { key: 'completed', label: t('work.tabCompleted') },
        { key: 'all', label: t('work.tabAll') },
    ];

    const [filtering, setFiltering] = useState(false);
    // Live search (debounced) on the same `query` param FollowUpQuery validates.
    useEffect(() => {
        if (query.trim() === (filters.query ?? '')) return;
        const timer = window.setTimeout(
            () =>
                router.get('/work', { tab, ...filters, query: query.trim() || undefined, focus: undefined, page: undefined }, {
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
    const assigneeOptions = [
        { value: 'all', label: t('work.assignedAll') },
        { value: 'unassigned', label: t('work.assignedUnassigned') },
        ...context.assignees.filter((user) => user.id !== context.current_user_id).map((user) => ({ value: String(user.id), label: user.name })),
    ];
    const chips: FilterChip[] = [];
    if (filters.query) chips.push({ key: 'query', label: t('filters.search'), value: filters.query, onRemove: () => setQuery('') });
    if (context.can_manage_all && filters.assigned_to && filters.assigned_to !== 'me') {
        chips.push({
            key: 'assigned_to',
            label: t('work.assignee'),
            value: assigneeOptions.find((option) => option.value === String(filters.assigned_to))?.label ?? String(filters.assigned_to),
            onRemove: () => visit({ assigned_to: undefined }),
        });
    }

    return (
        <ApplicationShell>
            <Head title={t('work.title')} />
            <PageHeader
                title={t('work.title')}
                description={t('work.description')}
                actions={context.can_create ? <Button onClick={() => setCreating(true)}>+ {t('work.new')}</Button> : undefined}
            />
            <WorkNav current="work" canManageAll={context.can_manage_all} />

            {!context.timezone_configured && (
                <p className="mb-4 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                    {t('work.timezoneUtc')} <Link href="/document-profile" className="text-primary hover:underline">{t('work.configureTimezone')}</Link>
                </p>
            )}

            <nav className="mb-4 flex gap-1 overflow-x-auto border-b border-line" aria-label={t('work.title')}>
                {tabs.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => router.get('/work', { ...filters, tab: item.key, focus: undefined }, { preserveState: true, replace: true })}
                        aria-current={tab === item.key ? 'page' : undefined}
                        className={`-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition-soft ${tab === item.key ? 'border-primary text-ink' : 'border-transparent text-ink-muted hover:text-ink'}`}
                    >
                        {item.label}
                        {item.count !== undefined && item.count > 0 && (
                            <span className={`rounded-full px-1.5 text-[11px] ${item.key === 'overdue' ? 'bg-warning-soft text-warning' : 'bg-sage text-ink-muted'}`}>{item.count}</span>
                        )}
                    </button>
                ))}
            </nav>

            {filters.focus && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-field border border-line bg-raised px-3 py-2 text-sm text-ink-muted">
                    <span>{t('work.focusTitle')}</span>
                    <Link href="/work" className="font-medium text-primary hover:underline">{t('work.focusShowAll')}</Link>
                </div>
            )}

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('work.title')}
                    search={<FilterSearch value={query} onChange={setQuery} loading={filtering && query.trim() !== (filters.query ?? '')} label={t('common.search')} placeholder={t('work.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={chips.length}
                    loading={filtering}
                    onReset={() => {
                        setQuery('');
                        visit({ query: undefined, assigned_to: undefined });
                    }}
                >
                    {context.can_manage_all && (
                        <ChoiceFilter
                            label={t('work.assignee')}
                            allLabel={t('work.assignedMe')}
                            searchable={assigneeOptions.length > 8}
                            options={assigneeOptions}
                            value={filters.assigned_to && filters.assigned_to !== 'me' ? String(filters.assigned_to) : undefined}
                            onChange={(value) => visit({ assigned_to: value })}
                        />
                    )}
                </AdvancedFilterBar>
            </div>

            {followUps.data.length === 0 ? (
                <EmptyState
                    title={filters.focus ? t('work.focusUnavailable') : chips.length > 0 ? t('filters.noMatch') : tab === 'today' ? t('work.emptyToday') : t('work.empty')}
                    description={chips.length > 0 ? t('filters.noMatchHelp') : t('work.emptyHelp')}
                    actions={
                        chips.length > 0 ? (
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    setQuery('');
                                    visit({ query: undefined, assigned_to: undefined });
                                }}
                            >
                                {t('filters.reset')}
                            </Button>
                        ) : context.can_create ? (
                            <Button onClick={() => setCreating(true)}>+ {t('work.new')}</Button>
                        ) : undefined
                    }
                />
            ) : (
                <FollowUpList items={followUps.data} context={context} showAssignee={context.can_manage_all && filters.assigned_to !== 'me'} />
            )}

            {followUps.data.length > 0 && (
                <div className="mt-4">
                    <TablePager links={followUps.links} total={followUps.total} />
                </div>
            )}

            {creating && <FollowUpFormDialog open onClose={() => setCreating(false)} context={context} />}
        </ApplicationShell>
    );
}
