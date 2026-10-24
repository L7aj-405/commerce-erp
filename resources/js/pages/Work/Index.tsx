import FollowUpFormDialog from '@/components/followups/FollowUpFormDialog';
import FollowUpList from '@/components/followups/FollowUpList';
import type { FollowUpContext, FollowUpItem } from '@/components/followups/types';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Tab = 'today' | 'overdue' | 'upcoming' | 'completed' | 'all';
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    tab: Tab;
    filters: { assigned_to?: string; query?: string; customer_id?: number; quotation_id?: number; date_from?: string; date_to?: string };
    followUps: { data: FollowUpItem[]; links: LinkData[]; total: number };
    counts: { today: number; overdue: number; upcoming: number };
    context: FollowUpContext;
};

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

export default function WorkIndex({ tab, filters, followUps, counts, context }: Props) {
    const { t } = useI18n();
    const [creating, setCreating] = useState(false);
    const [query, setQuery] = useState(filters.query ?? '');
    const visit = (next: Record<string, unknown>) => router.get('/work', { tab, ...filters, ...next, page: undefined }, { preserveState: true, replace: true });

    const tabs: { key: Tab; label: string; count?: number }[] = [
        { key: 'today', label: t('work.tabToday'), count: counts.today },
        { key: 'overdue', label: t('work.tabOverdue'), count: counts.overdue },
        { key: 'upcoming', label: t('work.tabUpcoming'), count: counts.upcoming },
        { key: 'completed', label: t('work.tabCompleted') },
        { key: 'all', label: t('work.tabAll') },
    ];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit({ query: query.trim() || undefined });
    };

    return (
        <ApplicationShell>
            <Head title={t('work.title')} />
            <PageHeader
                title={t('work.title')}
                description={t('work.description')}
                actions={context.can_create ? <Button onClick={() => setCreating(true)}>+ {t('work.new')}</Button> : undefined}
            />

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
                        onClick={() => router.get('/work', { ...filters, tab: item.key }, { preserveState: true, replace: true })}
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

            <form onSubmit={submit} className="mb-4 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('work.searchPlaceholder')} className={`${fieldClass} w-full min-w-0 flex-1 sm:min-w-72 sm:w-auto`} />
                {context.can_manage_all && (
                    <select value={filters.assigned_to ?? 'me'} onChange={(e) => visit({ assigned_to: e.target.value })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('work.assignee')}>
                        <option value="me">{t('work.assignedMe')}</option>
                        <option value="all">{t('work.assignedAll')}</option>
                        {context.assignees.filter((user) => user.id !== context.current_user_id).map((user) => <option key={user.id} value={String(user.id)}>{user.name}</option>)}
                    </select>
                )}
                <button type="submit" className="inline-flex min-h-10 flex-1 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised sm:flex-none">{t('common.search')}</button>
            </form>

            {followUps.data.length === 0 ? (
                <EmptyState
                    title={tab === 'today' ? t('work.emptyToday') : t('work.empty')}
                    description={t('work.emptyHelp')}
                    actions={context.can_create ? <Button onClick={() => setCreating(true)}>+ {t('work.new')}</Button> : undefined}
                />
            ) : (
                <FollowUpList items={followUps.data} context={context} showAssignee={context.can_manage_all && filters.assigned_to !== 'me'} />
            )}

            <Pagination links={followUps.links} />

            {creating && <FollowUpFormDialog open onClose={() => setCreating(false)} context={context} />}
        </ApplicationShell>
    );
}
