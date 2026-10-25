import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { useI18n } from '@/i18n';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { FollowUpContext, FollowUpItem, FollowUpPriority, FollowUpTemplate } from './types';
import { addDays } from './types';

const fieldClass = 'h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

type FormData = {
    title: string;
    note: string;
    priority: FollowUpPriority;
    due_date: string;
    due_time: string;
    assigned_user_id: number | '';
    customer_id: number | null;
    quotation_id: number | null;
    expected_version?: number;
};

/** Create (prefilled links) or edit a follow-up. Dates are organization-local; the server converts to UTC. */
export default function FollowUpFormDialog({
    open,
    onClose,
    context,
    followUp,
    prefill,
    defaults,
    submitUrl,
}: {
    open: boolean;
    onClose: () => void;
    context: FollowUpContext;
    followUp?: FollowUpItem | null;
    prefill?: { customer_id: number | null; quotation_id: number | null };
    /** W4.1: suggested values for a new follow-up (e.g. from an attention item). */
    defaults?: { title?: string; due_date?: string };
    /** W4.1: create through another canonical endpoint (attention bridge); defaults to /work. */
    submitUrl?: string;
}) {
    const { t } = useI18n();
    const editing = Boolean(followUp);
    const form = useForm<FormData>({
        title: followUp?.title ?? defaults?.title ?? '',
        note: followUp?.note ?? '',
        priority: followUp?.priority ?? 'normal',
        due_date: followUp?.due_date ?? (editing ? '' : defaults?.due_date ?? context.today),
        due_time: followUp?.due_time ?? '',
        // '' = Unassigned (manage_all only); new work defaults to the current user.
        assigned_user_id: followUp ? followUp.assignee?.id ?? '' : context.current_user_id,
        customer_id: followUp ? followUp.customer?.id ?? null : prefill?.customer_id ?? null,
        quotation_id: followUp ? followUp.quotation?.id ?? null : prefill?.quotation_id ?? null,
        ...(followUp ? { expected_version: followUp.version } : {}),
    });

    // W3: a template only pre-fills these fields; the server validates the follow-up as usual.
    const applyTemplate = (template: FollowUpTemplate | undefined) => {
        if (!template) return;
        form.setData((data) => ({
            ...data,
            title: template.title,
            note: template.default_note ?? '',
            priority: template.default_priority,
            due_date: template.default_due_offset_days !== null ? addDays(context.today, template.default_due_offset_days) : data.due_date,
            due_time: template.default_time ?? '',
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        form.transform((data) => {
            const payload: Partial<FormData> = { ...data, due_date: data.due_date || undefined, due_time: data.due_date ? data.due_time || undefined : undefined };
            if (editing) {
                // Links are kept as-is while editing; reassigning needs manage_all.
                delete payload.customer_id;
                delete payload.quotation_id;
                if (!payload.due_date) Object.assign(payload, { due_date: null, due_time: null });
            }
            if (!context.can_manage_all) delete payload.assigned_user_id;
            return { ...payload, ...(payload.assigned_user_id === '' ? { assigned_user_id: null } : {}) };
        });
        if (followUp) form.patch(`/work/${followUp.id}`, options);
        else form.post(submitUrl ?? '/work', options);
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={editing ? t('work.editTitle') : t('work.newTitle')}
            description={context.timezone_configured ? t('work.timezoneHelp').replace('{timezone}', context.timezone) : t('work.timezoneUtc')}
            footer={
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" form="follow-up-form" loading={form.processing} loadingText={t('common.saving')}>{t('common.save')}</Button>
                </div>
            }
        >
            <form id="follow-up-form" onSubmit={submit} className="space-y-4 px-4 py-4 sm:px-6">
                {(errors.version || errors.status) && (
                    <p className="rounded-field border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">{errors.version ?? errors.status}</p>
                )}
                {!editing && context.templates.length > 0 && (
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.tplChoose')}</span>
                        <select defaultValue="" onChange={(e) => applyTemplate(context.templates.find((template) => template.id === Number(e.target.value)))} className={fieldClass}>
                            <option value="">{t('work.tplNone')}</option>
                            {context.templates.map((template) => <option key={template.id} value={template.id}>{template.name}</option>)}
                        </select>
                    </label>
                )}
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.titleLabel')}</span>
                    <input data-autofocus required maxLength={200} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={fieldClass} />
                    {errors.title && <span className="mt-1 block text-xs text-danger">{errors.title}</span>}
                </label>
                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.dueDate')}</span>
                        <input type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} className={fieldClass} />
                        {errors.due_date && <span className="mt-1 block text-xs text-danger">{errors.due_date}</span>}
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.dueTime')}</span>
                        <input type="time" value={form.data.due_time} disabled={!form.data.due_date} onChange={(e) => form.setData('due_time', e.target.value)} className={fieldClass} />
                        <span className="mt-1 block text-xs text-ink-faint">{t('work.dueTimeHelp')}</span>
                        {errors.due_time && <span className="mt-1 block text-xs text-danger">{errors.due_time}</span>}
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.priority')}</span>
                        <select value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value as FollowUpPriority)} className={fieldClass}>
                            <option value="low">{t('work.priorityLow')}</option>
                            <option value="normal">{t('work.priorityNormal')}</option>
                            <option value="high">{t('work.priorityHigh')}</option>
                        </select>
                    </label>
                </div>
                {context.can_manage_all && context.assignees.length > 0 && (
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.assignee')}</span>
                        <select value={form.data.assigned_user_id} onChange={(e) => form.setData('assigned_user_id', e.target.value === '' ? '' : Number(e.target.value))} className={fieldClass}>
                            <option value="">{t('work.unassigned')}</option>
                            {/* A current assignee who is no longer assignable stays visible but cannot be picked again. */}
                            {followUp?.assignee && !context.assignees.some((user) => user.id === followUp.assignee?.id) && (
                                <option value={followUp.assignee.id} disabled>{t('work.inactiveAssignee').replace('{name}', followUp.assignee.name)}</option>
                            )}
                            {context.assignees.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                        </select>
                        {errors.assigned_user_id && <span className="mt-1 block text-xs text-danger">{errors.assigned_user_id}</span>}
                    </label>
                )}
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.note')}</span>
                    <textarea rows={3} maxLength={5000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                </label>
                {(errors.customer_id || errors.quotation_id) && <p className="text-xs text-danger">{errors.customer_id ?? errors.quotation_id}</p>}
            </form>
        </Dialog>
    );
}
