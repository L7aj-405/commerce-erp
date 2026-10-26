import RecurringFormDialog from '@/components/followups/RecurringFormDialog';
import WorkNav from '@/components/followups/WorkNav';
import { recurrenceSummary } from '@/components/followups/recurrence';
import type { FollowUpContext, RecurringRule } from '@/components/followups/types';
import { Button } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useToast } from '@/components/ui/toast';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    filters: { assigned_to?: string; status?: string };
    rules: { data: RecurringRule[]; links: LinkData[]; total: number };
    context: FollowUpContext;
};

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

export default function WorkRecurring({ filters, rules, context }: Props) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const [editing, setEditing] = useState<RecurringRule | null>(null);
    const [creating, setCreating] = useState(false);
    const [busy, setBusy] = useState<number | null>(null);
    const visit = (next: Record<string, unknown>) => router.get('/work/recurring', { ...filters, ...next, page: undefined }, { preserveState: true, replace: true });

    const transition = (rule: RecurringRule, name: 'pause' | 'resume' | 'end') => {
        setBusy(rule.id);
        router.post(`/work/recurring/${rule.id}/transition`, { transition: name, expected_version: rule.version }, {
            preserveScroll: true,
            onError: (errors) => toast.error(Object.values(errors)[0] ?? t('work.actionFailed')),
            onFinish: () => setBusy(null),
        });
    };

    const formatDate = (date: string) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-MA' : 'fr-FR', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${date}T00:00:00Z`));
    const statusBadge = { active: ['positive', t('work.recStatusActive')], paused: ['warning', t('work.recStatusPaused')], ended: ['neutral', t('work.recStatusEnded')] } as const;
    const reasonLabel: Record<string, string> = { assignee_lost: t('work.recReasonAssigneeLost'), quotation_not_current: t('work.recReasonQuotation'), schedule_complete: t('work.recReasonComplete') };

    return (
        <ApplicationShell>
            <Head title={t('work.recTitle')} />
            <PageHeader
                title={t('work.recTitle')}
                description={t('work.recDescription')}
                actions={context.can_create ? <Button onClick={() => setCreating(true)}>+ {t('work.recNew')}</Button> : undefined}
            />
            <WorkNav current="recurring" canManageAll={context.can_manage_all} />

            <div className="mb-4 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <select value={filters.status ?? ''} onChange={(e) => visit({ status: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('work.recFrequency')}>
                    <option value="">{t('work.tabAll')}</option>
                    <option value="active">{t('work.recStatusActive')}</option>
                    <option value="paused">{t('work.recStatusPaused')}</option>
                    <option value="ended">{t('work.recStatusEnded')}</option>
                </select>
                {context.can_manage_all && (
                    <select value={filters.assigned_to ?? 'me'} onChange={(e) => visit({ assigned_to: e.target.value })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('work.assignee')}>
                        <option value="me">{t('work.assignedMe')}</option>
                        <option value="all">{t('work.assignedAll')}</option>
                        <option value="unassigned">{t('work.assignedUnassigned')}</option>
                        {context.assignees.filter((user) => user.id !== context.current_user_id).map((user) => <option key={user.id} value={String(user.id)}>{user.name}</option>)}
                    </select>
                )}
            </div>

            {rules.data.length === 0 ? (
                <EmptyState title={t('work.recEmpty')} description={t('work.recEmptyHelp')}
                    actions={context.can_create ? <Button onClick={() => setCreating(true)}>+ {t('work.recNew')}</Button> : undefined} />
            ) : (
                <ul className="divide-y divide-line rounded-card border border-line bg-surface">
                    {rules.data.map((rule) => (
                        <li key={rule.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`text-sm font-semibold ${rule.status === 'ended' ? 'text-ink-muted' : 'text-ink'}`}>{rule.title}</span>
                                    <DocBadge tone={statusBadge[rule.status][0]}>{statusBadge[rule.status][1]}</DocBadge>
                                    {!rule.assignee && <DocBadge tone="warning">{t('work.unassigned')}</DocBadge>}
                                </div>
                                <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[13px] text-ink-muted">
                                    <span>{recurrenceSummary(rule, t, locale)}</span>
                                    {rule.next_occurrence_on && <span>{t('work.recNext').replace('{date}', formatDate(rule.next_occurrence_on))}</span>}
                                    {rule.customer && <Link href={`/sales/customers/${rule.customer.id}`} className="text-primary hover:underline">{rule.customer.name}</Link>}
                                    {rule.quotation && <span dir="ltr">{rule.quotation.number ?? t('work.draftQuotation')}</span>}
                                    {rule.assignee && context.can_manage_all && <span>{t('work.assignedTo').replace('{name}', rule.assignee.name)}</span>}
                                </p>
                                {rule.status_reason && reasonLabel[rule.status_reason] && <p className="mt-1 text-[13px] text-warning">{reasonLabel[rule.status_reason]}</p>}
                            </div>
                            {rule.can_manage && rule.status !== 'ended' && (
                                <div className="flex shrink-0 flex-wrap gap-2">
                                    <Button size="sm" variant="ghost" onClick={() => setEditing(rule)}>{t('common.edit')}</Button>
                                    {rule.status === 'active'
                                        ? <Button size="sm" variant="secondary" disabled={busy === rule.id} onClick={() => transition(rule, 'pause')}>{t('work.recPause')}</Button>
                                        : <Button size="sm" variant="success" disabled={busy === rule.id} onClick={() => transition(rule, 'resume')}>{t('work.recResume')}</Button>}
                                    <Button size="sm" variant="ghost" disabled={busy === rule.id} onClick={() => transition(rule, 'end')}>{t('work.recEnd')}</Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <Pagination links={rules.links} />

            {creating && <RecurringFormDialog open onClose={() => setCreating(false)} context={context} />}
            {editing && <RecurringFormDialog key={editing.id} open onClose={() => setEditing(null)} context={context} rule={editing} />}
        </ApplicationShell>
    );
}
