import { Button } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import { Dialog } from '@/components/ui/Dialog';
import { useToast } from '@/components/ui/toast';
import { useI18n } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import FollowUpFormDialog from './FollowUpFormDialog';
import type { FollowUpContext, FollowUpItem } from './types';
import { addDays } from './types';

/** Shared follow-up rows with quick actions (complete, snooze, edit, reopen) for My Work and detail-page panels. */
export default function FollowUpList({ items, context, showAssignee }: { items: FollowUpItem[]; context: FollowUpContext; showAssignee?: boolean }) {
    const { t, locale } = useI18n();
    const [editing, setEditing] = useState<FollowUpItem | null>(null);
    const [completing, setCompleting] = useState<FollowUpItem | null>(null);
    const [shipmentCompleting, setShipmentCompleting] = useState<FollowUpItem | null>(null);
    const [shipmentOutcome, setShipmentOutcome] = useState<'received_ok' | 'not_received' | 'customer_unreachable' | 'delivery_problem'>('received_ok');
    const [shipmentDueDate, setShipmentDueDate] = useState('');
    const [shipmentDueTime, setShipmentDueTime] = useState('');
    const [markDelivered, setMarkDelivered] = useState(true);
    const [outcome, setOutcome] = useState('');
    const [busy, setBusy] = useState<number | null>(null);
    const toast = useToast();
    const onError = (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? t('work.actionFailed'));

    const formatDate = (date: string) => {
        if (date === context.today) return t('work.today');
        if (date === addDays(context.today, 1)) return t('work.tomorrow');
        return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-MA' : 'fr-FR', { timeZone: 'UTC', day: 'numeric', month: 'short', year: date.slice(0, 4) === context.today.slice(0, 4) ? undefined : 'numeric' })
            .format(new Date(`${date}T00:00:00Z`));
    };

    const transition = (item: FollowUpItem, name: 'complete' | 'cancel' | 'reopen', extra: Record<string, string> = {}) => {
        setBusy(item.id);
        router.post(`/work/${item.id}/transition`, { transition: name, expected_version: item.version, ...extra }, {
            preserveScroll: true,
            onError,
            onFinish: () => setBusy(null),
        });
    };

    // Server-side: re-checks access, switches to the quotation's store if needed, then redirects.
    const openQuotation = (item: FollowUpItem) => router.post(`/work/${item.id}/open-quotation`, {}, { onError });
    const openInvoice = (item: FollowUpItem) => router.post(`/work/${item.id}/open-invoice`, {}, { onError });
    const openSalesOrder = (item: FollowUpItem) => router.post(`/work/${item.id}/open-sales-order`, {}, { onError });

    const snooze = (item: FollowUpItem, days: number) => {
        setBusy(item.id);
        router.patch(`/work/${item.id}`, {
            due_date: addDays(context.today, days),
            ...(item.due_time ? { due_time: item.due_time } : {}),
            expected_version: item.version,
        }, { preserveScroll: true, onError, onFinish: () => setBusy(null) });
    };

    const priorityTone = { high: 'danger', normal: 'neutral', low: 'neutral' } as const;
    const priorityLabel = { high: t('work.priorityHigh'), normal: t('work.priorityNormal'), low: t('work.priorityLow') };
    const openShipmentOutcome = (item: FollowUpItem) => {
        setOutcome('');
        setShipmentOutcome('received_ok');
        setShipmentDueDate(item.suggested_next_date);
        setShipmentDueTime(item.due_time ?? '');
        setMarkDelivered(item.shipment?.can_mark_delivered ?? false);
        setShipmentCompleting(item);
    };
    const submitShipmentOutcome = () => {
        if (!shipmentCompleting) return;
        setBusy(shipmentCompleting.id);
        const reschedule = shipmentOutcome === 'not_received' || shipmentOutcome === 'customer_unreachable';
        router.post(`/work/${shipmentCompleting.id}/shipment-outcome`, {
            outcome: shipmentOutcome,
            outcome_note: outcome || null,
            expected_version: shipmentCompleting.version,
            mark_delivered: shipmentOutcome === 'received_ok' ? markDelivered : false,
            ...(reschedule ? { due_date: shipmentDueDate, due_time: shipmentDueTime || null } : {}),
        }, {
            preserveScroll: true,
            onSuccess: () => setShipmentCompleting(null),
            onError,
            onFinish: () => setBusy(null),
        });
    };

    return (
        <>
            <ul className="divide-y divide-line rounded-card border border-line bg-surface">
                {items.map((item) => (
                    <li key={item.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={`text-sm font-semibold ${item.status === 'open' ? 'text-ink' : 'text-ink-muted line-through'}`}>{item.title}</span>
                                {item.priority !== 'normal' && <DocBadge tone={priorityTone[item.priority]}>{priorityLabel[item.priority]}</DocBadge>}
                                {item.status === 'completed' && <DocBadge tone="positive">{t('work.statusCompleted')}</DocBadge>}
                                {item.status === 'cancelled' && <DocBadge tone="neutral">{t('work.statusCancelled')}</DocBadge>}
                                {item.is_overdue && <DocBadge tone="warning">{t('work.overdueBadge')}</DocBadge>}
                                {item.status === 'open' && item.notified_at && <span className="text-[11px] text-ink-faint">{t('work.reminded')}</span>}
                                {item.recurring_rule_id !== null && <span className="text-[11px] text-ink-faint">{t('work.recurringBadge')}</span>}
                                {item.shipment && <DocBadge tone="info">{t('work.shipmentFollowUp')}</DocBadge>}
                            </div>
                            <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[13px] text-ink-muted">
                                <span>
                                    {item.due_date ? formatDate(item.due_date) : t('work.noDueDate')}
                                    {item.due_time && <span dir="ltr"> · {item.due_time}</span>}
                                </span>
                                {item.customer && <Link href={`/sales/customers/${item.customer.id}`} className="text-primary hover:underline">{item.customer.name}</Link>}
                                {item.quotation && (item.quotation.can_open ? (
                                    <button type="button" onClick={() => openQuotation(item)} className="text-primary hover:underline" dir="ltr">{item.quotation.number ?? t('work.draftQuotation')}</button>
                                ) : (
                                    <span title={t('work.quotationUnavailable')} dir="ltr">{item.quotation.number ?? t('work.draftQuotation')}</span>
                                ))}
                                {item.sales_order && (item.sales_order.can_open ? (
                                    <button type="button" onClick={() => openSalesOrder(item)} className="text-primary hover:underline" dir="ltr">{item.sales_order.number}</button>
                                ) : (
                                    <span title={t('work.salesOrderUnavailable')} dir="ltr">{item.sales_order.number}</span>
                                ))}
                                {item.invoice && (item.invoice.can_open ? (
                                    <button type="button" onClick={() => openInvoice(item)} className="text-primary hover:underline" dir="ltr">{item.invoice.number}</button>
                                ) : (
                                    <span title={t('work.invoiceUnavailable')} dir="ltr">{item.invoice.number}</span>
                                ))}
                                {showAssignee && item.assignee && <span>{t('work.assignedTo').replace('{name}', item.assignee.name)}</span>}
                                {!item.assignee && <DocBadge tone="warning">{t('work.unassigned')}</DocBadge>}
                            </p>
                            {item.note && <p className="mt-1 line-clamp-2 text-[13px] text-ink-faint">{item.note}</p>}
                            {item.outcome_note && <p className="mt-1 text-[13px] text-ink-muted">{t('work.outcome')}: {item.outcome_note}</p>}
                            {item.shipment_outcome && <p className="mt-1 text-[13px] font-medium text-ink-muted">{t(`work.shipmentOutcome.${item.shipment_outcome}` as never)}</p>}
                        </div>
                        {item.can_manage && (
                            <div className="flex shrink-0 flex-wrap gap-2">
                                {item.status === 'open' ? (
                                    <>
                                        <Button size="sm" variant="success" disabled={busy === item.id} onClick={() => item.shipment ? openShipmentOutcome(item) : (setOutcome(''), setCompleting(item))}>{t('work.complete')}</Button>
                                        <select
                                            aria-label={t('work.snooze')}
                                            value=""
                                            disabled={busy === item.id}
                                            onChange={(e) => e.target.value && snooze(item, Number(e.target.value))}
                                            className="h-8 rounded-field border border-line-strong bg-surface px-2 text-xs text-ink"
                                        >
                                            <option value="">{t('work.snooze')}</option>
                                            <option value="1">{t('work.snoozeTomorrow')}</option>
                                            <option value="3">{t('work.snoozeThreeDays')}</option>
                                            <option value="7">{t('work.snoozeNextWeek')}</option>
                                        </select>
                                        <Button size="sm" variant="ghost" onClick={() => setEditing(item)}>{t('common.edit')}</Button>
                                        <Button size="sm" variant="ghost" disabled={busy === item.id} onClick={() => transition(item, 'cancel')}>{t('work.cancelItem')}</Button>
                                    </>
                                ) : (
                                    <Button size="sm" variant="secondary" disabled={busy === item.id} onClick={() => transition(item, 'reopen')}>{t('work.reopen')}</Button>
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>

            {editing && <FollowUpFormDialog key={editing.id} open onClose={() => setEditing(null)} context={context} followUp={editing} />}

            <Dialog
                open={completing !== null}
                onClose={() => setCompleting(null)}
                title={t('work.completeTitle')}
                description={completing?.title}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setCompleting(null)}>{t('common.cancel')}</Button>
                        <Button
                            type="button"
                            loading={completing !== null && busy === completing.id}
                            onClick={() => {
                                if (!completing) return;
                                transition(completing, 'complete', outcome.trim() ? { outcome_note: outcome.trim() } : {});
                                setCompleting(null);
                            }}
                        >
                            {t('work.complete')}
                        </Button>
                    </div>
                }
            >
                <label className="block px-4 py-4 sm:px-6">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.outcomeOptional')}</span>
                    <textarea data-autofocus rows={3} maxLength={5000} value={outcome} onChange={(e) => setOutcome(e.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                </label>
            </Dialog>

            <Dialog
                open={shipmentCompleting !== null}
                onClose={() => busy === null && setShipmentCompleting(null)}
                title={t('work.shipmentCompleteTitle')}
                description={shipmentCompleting?.title}
                dismissible={busy === null}
                footer={<div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => setShipmentCompleting(null)}>{t('common.cancel')}</Button>
                    <Button type="button" variant={shipmentOutcome === 'delivery_problem' ? 'danger' : 'primary'} loading={busy !== null} onClick={submitShipmentOutcome}>{t('work.recordOutcome')}</Button>
                </div>}
            >
                <div className="space-y-4 px-4 py-5 sm:px-6">
                    <fieldset className="space-y-2">
                        <legend className="text-xs font-medium uppercase tracking-wide text-ink-faint">{t('work.shipmentOutcomeLabel')}</legend>
                        {(['received_ok', 'not_received', 'customer_unreachable', 'delivery_problem'] as const).map((value) => (
                            <label key={value} className="flex cursor-pointer items-start gap-2 rounded-field border border-line p-3 text-sm">
                                <input type="radio" name="shipment-outcome" value={value} checked={shipmentOutcome === value} onChange={() => setShipmentOutcome(value)} className="mt-0.5" />
                                <span><span className="font-medium text-ink">{t(`work.shipmentOutcome.${value}` as never)}</span><span className="mt-0.5 block text-xs text-ink-muted">{t(`work.shipmentOutcomeHelp.${value}` as never)}</span></span>
                            </label>
                        ))}
                    </fieldset>
                    {(shipmentOutcome === 'not_received' || shipmentOutcome === 'customer_unreachable') && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block"><span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.nextFollowUpDate')}</span><input required type="date" value={shipmentDueDate} onChange={(event) => setShipmentDueDate(event.target.value)} className="control-input w-full" /></label>
                            <label className="block"><span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.dueTime')}</span><input type="time" value={shipmentDueTime} onChange={(event) => setShipmentDueTime(event.target.value)} className="control-input w-full" /></label>
                        </div>
                    )}
                    {shipmentOutcome === 'received_ok' && shipmentCompleting?.shipment?.can_mark_delivered && shipmentCompleting.shipment.status !== 'delivered' && (
                        <label className="flex items-start gap-2 rounded-field border border-success/25 bg-success-soft p-3 text-sm"><input type="checkbox" checked={markDelivered} onChange={(event) => setMarkDelivered(event.target.checked)} className="mt-0.5" /><span><span className="font-medium text-ink">{t('work.markShipmentDelivered')}</span><span className="block text-xs text-ink-muted">{t('work.markShipmentDeliveredHelp')}</span></span></label>
                    )}
                    {shipmentOutcome === 'delivery_problem' && <p className="rounded-field border border-warning/30 bg-warning-soft p-3 text-sm text-warning">{t('work.deliveryProblemSupport')}</p>}
                    <label className="block"><span className="mb-1 block text-xs font-medium text-ink-muted">{t('work.outcomeOptional')}</span><textarea rows={3} maxLength={5000} value={outcome} onChange={(event) => setOutcome(event.target.value)} className="control-input w-full" /></label>
                </div>
            </Dialog>
        </>
    );
}
