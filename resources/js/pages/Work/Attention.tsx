import FollowUpFormDialog from '@/components/followups/FollowUpFormDialog';
import WorkNav from '@/components/followups/WorkNav';
import type { FollowUpContext } from '@/components/followups/types';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useToast } from '@/components/ui/toast';
import { useI18n } from '@/i18n';
import { fr, type TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type AttentionItem = {
    id: number;
    type: 'quotation_sent_undecided' | 'quotation_expiring_soon' | 'quotation_expired_undecided' | 'quotation_followup_backlog';
    severity: 'info' | 'warning' | 'critical';
    status: 'open' | 'snoozed' | 'dismissed' | 'resolved';
    title: string;
    summary: string;
    i18n: { title: string; message: string; params: Record<string, string> } | null;
    quotation: { id: number; number: string | null; can_open: boolean } | null;
    customer: { id: number; name: string } | null;
    detected_at: string;
    snoozed_until: string | null;
    dismissed_at: string | null;
    dismiss_note: string | null;
    resolved_at: string | null;
    follow_up_count: number;
    is_active: boolean;
    can_act: boolean;
    can_create_follow_up: boolean;
    suggestion: { title: string; due_date: string };
    version: number;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    filters: { view?: string; type?: string; severity?: string; customer_id?: number; quotation_id?: number };
    items: { data: AttentionItem[]; links: LinkData[]; total: number };
    context: FollowUpContext;
};

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';
const isKey = (value: string): value is TranslationKey => Object.prototype.hasOwnProperty.call(fr, value);

/** W4.1 Attention: detected quotation situations; every action is a human decision. */
export default function WorkAttention({ filters, items, context }: Props) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const [following, setFollowing] = useState<AttentionItem | null>(null);
    const [dismissing, setDismissing] = useState<AttentionItem | null>(null);
    const [snoozingDate, setSnoozingDate] = useState<AttentionItem | null>(null);
    const [note, setNote] = useState('');
    const [date, setDate] = useState('');
    const [busy, setBusy] = useState<number | null>(null);
    const onError = (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? t('work.actionFailed'));
    const visit = (next: Record<string, unknown>) => router.get('/work/attention', { ...filters, ...next, page: undefined }, { preserveState: true, replace: true });

    const text = (item: AttentionItem) => {
        const render = (key: string, fallback: string) => (item.i18n && isKey(key) ? t(key).replace(/\{(\w+)\}/g, (_, name: string) => item.i18n?.params[name] ?? '') : fallback);
        return { title: item.i18n ? render(item.i18n.title, item.title) : item.title, summary: item.i18n ? render(item.i18n.message, item.summary) : item.summary };
    };
    const formatDate = (value: string) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-MA' : 'fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));

    const post = (item: AttentionItem, path: string, data: Record<string, unknown>, after?: () => void) => {
        setBusy(item.id);
        router.post(`/work/attention/${item.id}/${path}`, { ...data, expected_version: item.version }, { preserveScroll: true, onError, onSuccess: after, onFinish: () => setBusy(null) });
    };
    const snooze = (item: AttentionItem, preset: string) => (preset === 'date' ? (setDate(''), setSnoozingDate(item)) : post(item, 'snooze', { preset }));

    const severity = { critical: ['danger', t('attention.severityCritical')], warning: ['warning', t('attention.severityWarning')], info: ['neutral', t('attention.severityInfo')] } as const;
    const views = [['open', t('attention.viewOpen')], ['snoozed', t('attention.viewSnoozed')], ['dismissed', t('attention.viewDismissed')], ['resolved', t('attention.viewResolved')], ['all', t('attention.viewAll')]] as const;

    return (
        <ApplicationShell>
            <Head title={t('attention.title')} />
            <PageHeader title={t('attention.title')} description={t('attention.description')} />
            <WorkNav current="attention" canManageAll={context.can_manage_all} />

            <nav className="mb-4 flex gap-1 overflow-x-auto border-b border-line" aria-label={t('attention.title')}>
                {views.map(([key, label]) => (
                    <button key={key} type="button" onClick={() => visit({ view: key })} aria-current={(filters.view ?? 'open') === key ? 'page' : undefined}
                        className={`-mb-px shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-soft ${(filters.view ?? 'open') === key ? 'border-primary text-ink' : 'border-transparent text-ink-muted hover:text-ink'}`}>
                        {label}
                    </button>
                ))}
            </nav>

            <div className="mb-4 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <select value={filters.type ?? ''} onChange={(e) => visit({ type: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('attention.allTypes')}>
                    <option value="">{t('attention.allTypes')}</option>
                    <option value="quotation_sent_undecided">{t('attention.typeSent')}</option>
                    <option value="quotation_expiring_soon">{t('attention.typeExpiring')}</option>
                    <option value="quotation_expired_undecided">{t('attention.typeExpired')}</option>
                    <option value="quotation_followup_backlog">{t('attention.typeBacklog')}</option>
                </select>
                <select value={filters.severity ?? ''} onChange={(e) => visit({ severity: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('attention.allSeverities')}>
                    <option value="">{t('attention.allSeverities')}</option>
                    <option value="critical">{t('attention.severityCritical')}</option>
                    <option value="warning">{t('attention.severityWarning')}</option>
                </select>
            </div>

            {items.data.length === 0 ? (
                <EmptyState title={t('attention.empty')} description={t('attention.emptyHelp')} />
            ) : (
                <ul className="divide-y divide-line rounded-card border border-line bg-surface">
                    {items.data.map((item) => (
                        <li key={item.id} className="flex flex-col gap-3 p-4 lg:flex-row lg:items-start lg:justify-between">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`text-sm font-semibold ${item.is_active ? 'text-ink' : 'text-ink-muted'}`}>{text(item).title}</span>
                                    <DocBadge tone={severity[item.severity][0]}>{severity[item.severity][1]}</DocBadge>
                                    {item.status === 'snoozed' && item.snoozed_until && <DocBadge tone="neutral">{t('attention.statusSnoozed').replace('{date}', formatDate(item.snoozed_until))}</DocBadge>}
                                    {item.status === 'dismissed' && <DocBadge tone="neutral">{t('attention.statusDismissed')}</DocBadge>}
                                    {item.status === 'resolved' && <DocBadge tone="positive">{t('attention.statusResolved')}</DocBadge>}
                                </div>
                                <p className="mt-1 text-[13px] text-ink-muted">{text(item).summary}</p>
                                <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[12px] text-ink-faint">
                                    {item.customer && <Link href={`/sales/customers/${item.customer.id}`} className="text-primary hover:underline">{item.customer.name}</Link>}
                                    <span>{t('attention.detected').replace('{date}', formatDate(item.detected_at))}</span>
                                    {item.follow_up_count > 0 && <span>{t('attention.followUpsCreated').replace('{count}', String(item.follow_up_count))}</span>}
                                    {item.dismiss_note && <span>« {item.dismiss_note} »</span>}
                                </p>
                            </div>
                            <div className="flex shrink-0 flex-wrap gap-2">
                                {item.quotation?.can_open && <Button size="sm" variant="ghost" onClick={() => router.post(`/work/attention/${item.id}/open-quotation`, {}, { onError })}>{t('attention.openQuotation')}</Button>}
                                {item.can_act && item.can_create_follow_up && <Button size="sm" onClick={() => setFollowing(item)}>{t('attention.createFollowUp')}</Button>}
                                {item.can_act && (
                                    <>
                                        <select aria-label={t('attention.snooze')} value="" disabled={busy === item.id} onChange={(e) => e.target.value && snooze(item, e.target.value)}
                                            className="h-8 rounded-field border border-line-strong bg-surface px-2 text-xs text-ink">
                                            <option value="">{t('attention.snooze')}</option>
                                            <option value="later_today">{t('attention.snoozeLaterToday')}</option>
                                            <option value="tomorrow">{t('attention.snoozeTomorrow')}</option>
                                            <option value="in_3_days">{t('attention.snooze3Days')}</option>
                                            <option value="next_week">{t('attention.snoozeNextWeek')}</option>
                                            <option value="date">{t('attention.snoozeDate')}</option>
                                        </select>
                                        <Button size="sm" variant="ghost" disabled={busy === item.id} onClick={() => { setNote(''); setDismissing(item); }}>{t('attention.dismiss')}</Button>
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <Pagination links={items.links} />

            {following && (
                <FollowUpFormDialog open onClose={() => setFollowing(null)} context={context} submitUrl={`/work/attention/${following.id}/follow-up`}
                    defaults={{ title: following.suggestion.title, due_date: following.suggestion.due_date }} />
            )}

            <Dialog open={dismissing !== null} onClose={() => setDismissing(null)} title={t('attention.dismissTitle')} description={t('attention.dismissHelp')}
                footer={<div className="flex justify-end gap-2"><Button type="button" variant="secondary" onClick={() => setDismissing(null)}>{t('common.cancel')}</Button>
                    <Button type="button" onClick={() => dismissing && post(dismissing, 'dismiss', note.trim() ? { note: note.trim() } : {}, () => setDismissing(null))}>{t('attention.dismiss')}</Button></div>}>
                <label className="block px-4 py-4 sm:px-6">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('attention.dismissNote')}</span>
                    <textarea data-autofocus rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                </label>
            </Dialog>

            <Dialog open={snoozingDate !== null} onClose={() => setSnoozingDate(null)} title={t('attention.snoozeDateTitle')}
                footer={<div className="flex justify-end gap-2"><Button type="button" variant="secondary" onClick={() => setSnoozingDate(null)}>{t('common.cancel')}</Button>
                    <Button type="button" disabled={!date} onClick={() => snoozingDate && post(snoozingDate, 'snooze', { preset: 'date', until_date: date }, () => setSnoozingDate(null))}>{t('attention.snooze')}</Button></div>}>
                <label className="block px-4 py-4 sm:px-6">
                    <input data-autofocus type="date" min={context.today} value={date} onChange={(e) => setDate(e.target.value)} className={`${fieldClass} w-full`} />
                </label>
            </Dialog>
        </ApplicationShell>
    );
}
