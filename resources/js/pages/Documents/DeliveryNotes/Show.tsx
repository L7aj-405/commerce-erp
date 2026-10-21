import { Button } from '@/components/ui/Button';
import SendDocumentEmailModal from '@/components/documents/SendDocumentEmailModal';
import DocBadge from '@/components/ui/DocBadge';
import { formatDate, formatDateTime } from '@/utils/format';
import { deliveryNoteStatusLabel, deliveryNoteStatusTone, label } from '@/utils/labels';
import SalesLayout from '@/layouts/SalesLayout';
import { useI18n } from '@/i18n';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

type Line = { id: number; description: string; product_name: string | null; variant_name: string | null; sku: string | null; reference: string | null; unit_label: string | null; quantity: string };
type Note = {
    id: number;
    delivery_note_number: string | null;
    status: string;
    delivery_date: string;
    recipient_name: string | null;
    recipient_company: string | null;
    recipient_phone: string | null;
    delivery_address: string | null;
    notes: string | null;
    issued_at: string | null;
    cancellation_reason: string | null;
    store: { name: string; code: string };
    sales_order: { id: number; order_number: string };
    lines: Line[];
    issued_by: { name: string } | null;
};
type Props = { deliveryNote: Note; can: { updateDraft: boolean; issue: boolean; backdate: boolean; email: boolean; configureMail: boolean }; mailConfigured: boolean };

const fieldClass = 'mt-1.5 h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';
const labelClass = 'block text-[13px] font-medium text-ink';

function Card({ label: title, children }: { label: string; children: ReactNode }) {
    return (
        <div className="rounded-card border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-muted">{title}</p>
            <div className="mt-1 text-sm font-semibold text-ink">{children}</div>
        </div>
    );
}

export default function DeliveryNoteShow({ deliveryNote: note, can, mailConfigured }: Props) {
    const { t } = useI18n();
    const statusLabel: Record<string, string> = { draft: t('deliveryNotes.draft'), issued: t('deliveryNotes.issued'), cancelled: t('deliveryNotes.cancelled') };
    const form = useForm({
        delivery_date: note.delivery_date.slice(0, 10),
        recipient_name: note.recipient_name ?? '',
        recipient_company: note.recipient_company ?? '',
        recipient_phone: note.recipient_phone ?? '',
        delivery_address: note.delivery_address ?? '',
        notes: note.notes ?? '',
    });
    const cancellation = useForm({ reason: '' });
    const [emailModalOpen, setEmailModalOpen] = useState(false);
    const update = (event: FormEvent) => { event.preventDefault(); form.patch(`/delivery-notes/${note.id}`, { preserveScroll: true }); };
    const cancel = (event: FormEvent) => { event.preventDefault(); cancellation.post(`/delivery-notes/${note.id}/cancel`, { preserveScroll: true }); };
    const title = note.delivery_note_number ?? t('deliveryNotes.draftNumber').replace('{id}', String(note.id));

    return (
        <SalesLayout>
            <Head title={title} />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link href="/delivery-notes" className="text-sm text-ink-muted">
                        <span aria-hidden="true">←</span> {t('deliveryNotes.back')}
                    </Link>
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">{title}</h1>
                        <DocBadge tone={deliveryNoteStatusTone(note.status)}>{statusLabel[note.status] ?? note.status}</DocBadge>
                    </div>
                    <p className="text-sm text-ink-muted">
                        {t('deliveryNotes.order')} <Link href={`/sales/orders/${note.sales_order.id}`}><bdi>{note.sales_order.order_number}</bdi></Link> · <bdi>{note.store.code}</bdi> · {note.store.name}
                    </p>
                </div>
                {note.status === 'draft' && can.issue && (
                    <Button onClick={() => router.post(`/delivery-notes/${note.id}/issue`)}>{t('deliveryNotes.issue')}</Button>
                )}
            </div>

            <section className="mb-6 grid gap-3 sm:grid-cols-3">
                <Card label={t('deliveryNotes.deliveryDate')}>{formatDate(note.delivery_date)}</Card>
                <Card label={t('common.status')}>{statusLabel[note.status] ?? note.status}</Card>
                <Card label={t('deliveryNotes.issuance')}>{note.issued_at ? `${note.issued_by?.name ?? t('deliveryNotes.unknown')} · ${formatDateTime(note.issued_at)}` : t('deliveryNotes.notIssued')}</Card>
            </section>

            <section className="mb-6 flex flex-wrap items-end gap-3 rounded-card border border-line bg-surface p-5">
                <a href={note.status === 'draft' ? `/delivery-notes/${note.id}/print` : `/delivery-notes/${note.id}/pdf`} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink transition-soft hover:bg-raised">
                    {note.status === 'draft' ? t('deliveryNotes.preview') : t('deliveryNotes.viewPdf')}
                </a>
                {note.status === 'issued' && (
                    <a href={`/delivery-notes/${note.id}/download`} className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg transition-soft hover:bg-primary-hover">
                        {t('deliveryNotes.downloadPdf')}
                    </a>
                )}
                {note.status === 'issued' && can.email && (
                    <Button type="button" variant="secondary" onClick={() => setEmailModalOpen(true)}>
                        {t('deliveryNotes.email')}
                    </Button>
                )}
            </section>

            {note.status === 'draft' && can.updateDraft && (
                <form onSubmit={update} className="mb-6 grid gap-4 rounded-card border border-line bg-surface p-5 md:grid-cols-2">
                    <h3 className="text-sm font-semibold text-ink md:col-span-2">{t('deliveryNotes.draftInfo')}</h3>
                    <label className={labelClass}>
                        {t('deliveryNotes.deliveryDate')}
                        <input type="date" readOnly={!can.backdate} value={form.data.delivery_date} onChange={(e) => form.setData('delivery_date', e.target.value)} className={`${fieldClass} read-only:bg-raised`} />
                    </label>
                    <label className={labelClass}>
                        {t('deliveryNotes.recipientName')}
                        <input value={form.data.recipient_name} onChange={(e) => form.setData('recipient_name', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        {t('deliveryNotes.company')}
                        <input value={form.data.recipient_company} onChange={(e) => form.setData('recipient_company', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        {t('deliveryNotes.phone')}
                        <input value={form.data.recipient_phone} onChange={(e) => form.setData('recipient_phone', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={`${labelClass} md:col-span-2`}>
                        {t('deliveryNotes.address')}
                        <textarea rows={2} value={form.data.delivery_address} onChange={(e) => form.setData('delivery_address', e.target.value)} className={`${fieldClass} h-auto py-2`} />
                    </label>
                    <label className={`${labelClass} md:col-span-2`}>
                        {t('deliveryNotes.notes')}
                        <textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={`${fieldClass} h-auto py-2`} />
                    </label>
                    {Object.values(form.errors).map((error) => error && <p key={error} className="text-[13px] text-danger md:col-span-2">{error}</p>)}
                    <div className="md:col-span-2">
                        <Button type="submit" variant="secondary" loading={form.processing} loadingText="Enregistrement…">
                            {t('deliveryNotes.saveDraft')}
                        </Button>
                    </div>
                </form>
            )}

            {note.status !== 'draft' && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h3 className="text-sm font-semibold text-ink">{t('deliveryNotes.recipientAtIssue')}</h3>
                    <p className="mt-2 text-sm text-ink">{note.recipient_company ?? note.recipient_name ?? t('common.counterCustomer')}</p>
                    {note.recipient_phone && <p className="text-sm text-ink-muted">{note.recipient_phone}</p>}
                    {note.delivery_address && <p className="whitespace-pre-wrap text-sm text-ink-muted">{note.delivery_address}</p>}
                </section>
            )}

            <div className="mb-6 overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full text-start text-sm">
                    <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-4 py-2.5">{t('deliveryNotes.item')}</th><th className="px-4 py-2.5">{t('deliveryNotes.reference')}</th><th className="px-4 py-2.5 text-end">{t('deliveryNotes.deliveredQuantity')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {note.lines.map((line) => (
                            <tr key={line.id} className="border-t border-line">
                                <td className="px-4 py-2.5">
                                    <p className="font-medium text-ink">{line.product_name ?? line.description}</p>
                                    {(line.variant_name || line.sku) && <p className="text-[12px] text-ink-muted">{[line.variant_name, line.sku].filter(Boolean).join(' · ')}</p>}
                                </td>
                                <td className="px-4 py-2.5 text-ink-muted"><bdi>{line.reference ?? '—'}</bdi></td>
                                <td className="px-4 py-2.5 text-end">{line.quantity} {line.unit_label}</td>
                            </tr>
                        ))}
                        {note.lines.length === 0 && (
                            <tr>
                                <td colSpan={3} className="px-4 py-8 text-center text-ink-faint">{t('deliveryNotes.noItems')}</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {note.status === 'draft' && can.updateDraft && (
                <form onSubmit={cancel} className="max-w-xl space-y-3 rounded-card border border-danger/30 bg-danger-soft p-5">
                    <h3 className="text-sm font-semibold text-danger">{t('deliveryNotes.cancelDraft')}</h3>
                    <textarea
                        value={cancellation.data.reason}
                        onChange={(e) => cancellation.setData('reason', e.target.value)}
                        placeholder={t('deliveryNotes.reasonOptional')}
                        className="w-full rounded-field border border-danger/30 bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-danger"
                    />
                    <button type="submit" className="inline-flex min-h-10 items-center justify-center rounded-field border border-danger/40 px-4 text-sm font-medium text-danger transition-soft hover:bg-danger-soft">
                        {t('deliveryNotes.cancelDraft')}
                    </button>
                </form>
            )}
            {note.status === 'cancelled' && (
                <p className="rounded-card border border-warning/30 bg-warning-soft p-5 text-sm text-warning">
                    {t('deliveryNotes.cancelledReason').replace('{reason}', note.cancellation_reason ?? t('deliveryNotes.noReason'))}
                </p>
            )}
            <SendDocumentEmailModal
                open={emailModalOpen}
                onClose={() => setEmailModalOpen(false)}
                postUrl={`/delivery-notes/${note.id}/email`}
                title={t('deliveryNotes.emailTitle')}
                defaultTo=""
                defaultSubject={t('deliveryNotes.emailSubject').replace('{number}', title)}
                defaultMessage={t('deliveryNotes.emailMessage')}
                attachmentName={`Bon-de-Livraison-${title}.pdf`}
                canSend={can.email}
                mailConfigured={mailConfigured}
                canConfigureMail={can.configureMail}
            />
        </SalesLayout>
    );
}
