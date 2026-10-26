import WorkNav from '@/components/followups/WorkNav';
import type { FollowUpPriority, FollowUpTemplate } from '@/components/followups/types';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useToast } from '@/components/ui/toast';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type LinkData = { url: string | null; label: string; active: boolean };
type Props = { templates: { data: FollowUpTemplate[]; links: LinkData[]; total: number } };

const fieldClass = 'h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

/** Organization follow-up templates (follow_ups.manage_all): pre-fill presets, never automation. */
export default function WorkTemplates({ templates }: Props) {
    const { t } = useI18n();
    const toast = useToast();
    const [editing, setEditing] = useState<FollowUpTemplate | null>(null);
    const [creating, setCreating] = useState(false);
    const toggle = (template: FollowUpTemplate) => router.patch(`/work/templates/${template.id}`, { active: !template.active }, {
        preserveScroll: true,
        onError: (errors) => toast.error(Object.values(errors)[0] ?? t('work.actionFailed')),
    });
    const priorityLabel = { high: t('work.priorityHigh'), normal: t('work.priorityNormal'), low: t('work.priorityLow') };

    return (
        <ApplicationShell>
            <Head title={t('work.tplTitle')} />
            <PageHeader title={t('work.tplTitle')} description={t('work.tplDescription')} actions={<Button onClick={() => setCreating(true)}>+ {t('work.tplNew')}</Button>} />
            <WorkNav current="templates" canManageAll />

            {templates.data.length === 0 ? (
                <EmptyState title={t('work.tplEmpty')} description={t('work.tplDescription')} actions={<Button onClick={() => setCreating(true)}>+ {t('work.tplNew')}</Button>} />
            ) : (
                <ul className="divide-y divide-line rounded-card border border-line bg-surface">
                    {templates.data.map((template) => (
                        <li key={template.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`text-sm font-semibold ${template.active ? 'text-ink' : 'text-ink-muted'}`}>{template.name}</span>
                                    {!template.active && <DocBadge tone="neutral">{t('work.tplInactive')}</DocBadge>}
                                </div>
                                <p className="mt-1 flex flex-wrap gap-x-3 text-[13px] text-ink-muted">
                                    <span>{template.title}</span>
                                    <span>{priorityLabel[template.default_priority]}</span>
                                    {template.default_due_offset_days !== null && <span>{t('work.tplOffsetShort').replace('{n}', String(template.default_due_offset_days))}</span>}
                                    {template.default_time && <span dir="ltr">{template.default_time}</span>}
                                </p>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                <Button size="sm" variant="ghost" onClick={() => setEditing(template)}>{t('common.edit')}</Button>
                                <Button size="sm" variant="secondary" onClick={() => toggle(template)}>{template.active ? t('work.tplDeactivate') : t('work.tplActivate')}</Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <Pagination links={templates.links} />

            {creating && <TemplateDialog onClose={() => setCreating(false)} />}
            {editing && <TemplateDialog key={editing.id} template={editing} onClose={() => setEditing(null)} />}
        </ApplicationShell>
    );
}

function TemplateDialog({ template, onClose }: { template?: FollowUpTemplate; onClose: () => void }) {
    const { t } = useI18n();
    const form = useForm({
        name: template?.name ?? '',
        title: template?.title ?? '',
        default_note: template?.default_note ?? '',
        default_priority: (template?.default_priority ?? 'normal') as FollowUpPriority,
        default_due_offset_days: (template?.default_due_offset_days ?? '') as number | '',
        default_time: template?.default_time ?? '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, default_due_offset_days: data.default_due_offset_days === '' ? null : data.default_due_offset_days, default_time: data.default_time || null }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (template) form.patch(`/work/templates/${template.id}`, options);
        else form.post('/work/templates', options);
    };
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open onClose={onClose} title={template ? t('work.tplEdit') : t('work.tplNew')} description={t('work.tplDescription')}
            footer={<div className="flex justify-end gap-2"><Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="template-form" loading={form.processing} loadingText={t('common.saving')}>{t('common.save')}</Button></div>}>
            <form id="template-form" onSubmit={submit} className="space-y-4 px-4 py-4 sm:px-6">
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.tplName')}</span>
                    <input data-autofocus required maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={fieldClass} />
                    {errors.name && <span className="mt-1 block text-xs text-danger">{errors.name}</span>}
                </label>
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.titleLabel')}</span>
                    <input required maxLength={200} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={fieldClass} />
                    {errors.title && <span className="mt-1 block text-xs text-danger">{errors.title}</span>}
                </label>
                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.priority')}</span>
                        <select value={form.data.default_priority} onChange={(e) => form.setData('default_priority', e.target.value as FollowUpPriority)} className={fieldClass}>
                            <option value="low">{t('work.priorityLow')}</option>
                            <option value="normal">{t('work.priorityNormal')}</option>
                            <option value="high">{t('work.priorityHigh')}</option>
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.tplOffset')}</span>
                        <input type="number" min={0} max={365} value={form.data.default_due_offset_days} onChange={(e) => form.setData('default_due_offset_days', e.target.value === '' ? '' : Number(e.target.value))} className={fieldClass} />
                        {errors.default_due_offset_days && <span className="mt-1 block text-xs text-danger">{errors.default_due_offset_days}</span>}
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.tplTime')}</span>
                        <input type="time" value={form.data.default_time} onChange={(e) => form.setData('default_time', e.target.value)} className={fieldClass} />
                    </label>
                </div>
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.note')}</span>
                    <textarea rows={3} maxLength={5000} value={form.data.default_note} onChange={(e) => form.setData('default_note', e.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                </label>
            </form>
        </Dialog>
    );
}
