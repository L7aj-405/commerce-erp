import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { useI18n } from '@/i18n';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { weekdayName } from './recurrence';
import type { FollowUpContext, FollowUpPriority, RecurrenceFrequency, RecurringRule } from './types';

const fieldClass = 'h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

type FormData = {
    title: string;
    note: string;
    priority: FollowUpPriority;
    assigned_user_id: number | '';
    frequency: RecurrenceFrequency;
    interval_count: number;
    weekdays: number[];
    month_day: number | '';
    local_time: string;
    starts_on: string;
    ends_on: string;
    max_occurrences: number | '';
    customer_id: number | null;
    quotation_id: number | null;
    expected_version?: number;
};

/** Context link shown read-only; the store always derives server-side from the quotation. */
export type RecurringLink = { customer_id: number | null; quotation_id: number | null; customer_name: string | null; quotation_number: string | null };

/** Create or edit a recurring rule. Dates/times are organization-local; edits apply to future occurrences only. */
export default function RecurringFormDialog({ open, onClose, context, rule, link }: { open: boolean; onClose: () => void; context: FollowUpContext; rule?: RecurringRule | null; link?: RecurringLink }) {
    const { t, locale } = useI18n();
    const editing = Boolean(rule);
    const form = useForm<FormData>({
        title: rule?.title ?? '',
        note: rule?.note ?? '',
        priority: rule?.priority ?? 'normal',
        assigned_user_id: rule ? rule.assignee?.id ?? '' : context.current_user_id,
        frequency: rule?.frequency ?? 'weekly',
        interval_count: rule?.interval_count ?? 1,
        weekdays: rule?.weekdays ?? [],
        month_day: rule?.month_day ?? '',
        local_time: rule?.local_time ?? '',
        starts_on: rule?.starts_on ?? context.today,
        ends_on: rule?.ends_on ?? '',
        max_occurrences: rule?.max_occurrences ?? '',
        customer_id: rule ? rule.customer?.id ?? null : link?.customer_id ?? null,
        quotation_id: rule ? rule.quotation?.id ?? null : link?.quotation_id ?? null,
        ...(rule ? { expected_version: rule.version } : {}),
    });
    const linkLabels = rule
        ? { customer: rule.customer?.name ?? null, quotation: rule.quotation ? rule.quotation.number ?? t('work.draftQuotation') : null }
        : { customer: link?.customer_name ?? null, quotation: link?.quotation_id ? link.quotation_number ?? t('work.draftQuotation') : null };
    const hadLink = Boolean(rule ? rule.customer || rule.quotation : link?.customer_id || link?.quotation_id);
    const linked = form.data.customer_id !== null || form.data.quotation_id !== null;

    const toggleDay = (day: number) => form.setData('weekdays', form.data.weekdays.includes(day) ? form.data.weekdays.filter((d) => d !== day) : [...form.data.weekdays, day].sort());

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const blank = <T,>(value: T | '') => (value === '' ? null : value);
        form.transform((data) => {
            const payload: Record<string, unknown> = {
                ...data,
                assigned_user_id: blank(data.assigned_user_id),
                month_day: data.frequency === 'monthly' ? blank(data.month_day) : null,
                weekdays: data.frequency === 'weekly' ? data.weekdays : [],
                local_time: data.local_time || null,
                ends_on: data.ends_on || null,
                max_occurrences: blank(data.max_occurrences),
            };
            if (!context.can_manage_all) delete payload.assigned_user_id;
            // Links are sent on create, or on edit only when removed; the server revalidates them.
            if (rule && linked) {
                delete payload.customer_id;
                delete payload.quotation_id;
            }
            return payload;
        });
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (rule) form.patch(`/work/recurring/${rule.id}`, options);
        else form.post('/work/recurring', options);
    };

    const errors = form.errors as Record<string, string | undefined>;
    const error = (key: string) => errors[key] && <span className="mt-1 block text-xs text-danger">{errors[key]}</span>;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={editing ? t('work.recEdit') : t('work.recNew')}
            description={editing ? t('work.recEditNote') : (context.timezone_configured ? t('work.timezoneHelp').replace('{timezone}', context.timezone) : t('work.timezoneUtc'))}
            footer={
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" form="recurring-form" loading={form.processing} loadingText={t('common.saving')}>{t('common.save')}</Button>
                </div>
            }
        >
            <form id="recurring-form" onSubmit={submit} className="space-y-4 px-4 py-4 sm:px-6">
                {(errors.version || errors.status) && <p className="rounded-field border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">{errors.version ?? errors.status}</p>}
                {hadLink && (
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-field border border-line bg-raised px-3 py-2 text-sm">
                        {linked ? (
                            <span className="flex flex-wrap gap-x-3 text-ink">
                                {linkLabels.customer && <span>{t('work.recLinkCustomer').replace('{name}', linkLabels.customer)}</span>}
                                {linkLabels.quotation && <span>{t('work.recLinkQuotation')} <span dir="ltr">{linkLabels.quotation}</span></span>}
                                {form.data.quotation_id !== null && <span className="text-xs text-ink-faint">{t('work.recLinkStoreDerived')}</span>}
                            </span>
                        ) : <span className="text-ink-muted">{t('work.recLinkRemoved')}</span>}
                        {linked && <button type="button" onClick={() => form.setData((data) => ({ ...data, customer_id: null, quotation_id: null }))} className="text-xs font-medium text-primary hover:underline">{t('work.recLinkRemove')}</button>}
                    </div>
                )}
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.titleLabel')}</span>
                    <input data-autofocus required maxLength={200} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={fieldClass} />
                    {error('title')}
                </label>
                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.recFrequency')}</span>
                        <select value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value as RecurrenceFrequency)} className={fieldClass}>
                            <option value="daily">{t('work.recDailyOpt')}</option>
                            <option value="weekly">{t('work.recWeeklyOpt')}</option>
                            <option value="monthly">{t('work.recMonthlyOpt')}</option>
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.recInterval')}</span>
                        <input type="number" min={1} max={99} value={form.data.interval_count} onChange={(e) => form.setData('interval_count', Math.max(1, Number(e.target.value) || 1))} className={fieldClass} />
                        {error('interval_count')}
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.recTime')}</span>
                        <input type="time" value={form.data.local_time} onChange={(e) => form.setData('local_time', e.target.value)} className={fieldClass} />
                        {error('local_time')}
                    </label>
                </div>
                <p className="-mt-2 text-xs text-ink-faint">{t('work.recTimeHelp')}</p>
                {form.data.frequency === 'weekly' && (
                    <fieldset>
                        <legend className="mb-1 block text-xs font-medium text-ink-muted">{t('work.recWeekdays')}</legend>
                        <div className="flex flex-wrap gap-1.5">
                            {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                                <button key={day} type="button" aria-pressed={form.data.weekdays.includes(day)} onClick={() => toggleDay(day)}
                                    className={`min-h-8 rounded-field border px-3 text-xs font-medium transition-soft ${form.data.weekdays.includes(day) ? 'border-primary bg-primary text-primary-fg' : 'border-line-strong bg-surface text-ink'}`}>
                                    {weekdayName(day, locale)}
                                </button>
                            ))}
                        </div>
                        {error('weekdays')}
                    </fieldset>
                )}
                {form.data.frequency === 'monthly' && (
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.recMonthDay')}</span>
                        <input type="number" min={1} max={31} value={form.data.month_day} onChange={(e) => form.setData('month_day', e.target.value === '' ? '' : Number(e.target.value))} className={fieldClass} />
                        <span className="mt-1 block text-xs text-ink-faint">{t('work.recMonthDayHelp')}</span>
                        {error('month_day')}
                    </label>
                )}
                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.recStartsOn')}</span>
                        <input type="date" required value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} className={fieldClass} />
                        {error('starts_on')}
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.recEndsOn')}</span>
                        <input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} className={fieldClass} />
                        {error('ends_on')}
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.recMax')}</span>
                        <input type="number" min={1} value={form.data.max_occurrences} onChange={(e) => form.setData('max_occurrences', e.target.value === '' ? '' : Number(e.target.value))} className={fieldClass} />
                        {error('max_occurrences')}
                    </label>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.priority')}</span>
                        <select value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value as FollowUpPriority)} className={fieldClass}>
                            <option value="low">{t('work.priorityLow')}</option>
                            <option value="normal">{t('work.priorityNormal')}</option>
                            <option value="high">{t('work.priorityHigh')}</option>
                        </select>
                    </label>
                    {context.can_manage_all && (
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.assignee')}</span>
                            <select value={form.data.assigned_user_id} onChange={(e) => form.setData('assigned_user_id', e.target.value === '' ? '' : Number(e.target.value))} className={fieldClass}>
                                <option value="">{t('work.unassigned')}</option>
                                {rule?.assignee && !context.assignees.some((user) => user.id === rule.assignee?.id) && (
                                    <option value={rule.assignee.id} disabled>{t('work.inactiveAssignee').replace('{name}', rule.assignee.name)}</option>
                                )}
                                {context.assignees.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                            </select>
                            {error('assigned_user_id')}
                        </label>
                    )}
                </div>
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.note')}</span>
                    <textarea rows={2} maxLength={5000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                </label>
                {(errors.customer_id || errors.quotation_id) && <p className="text-xs text-danger">{errors.customer_id ?? errors.quotation_id}</p>}
            </form>
        </Dialog>
    );
}
