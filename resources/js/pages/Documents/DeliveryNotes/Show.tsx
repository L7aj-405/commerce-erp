import SendDocumentEmailModal from '@/components/documents/SendDocumentEmailModal';
import SandboxDocumentBadge from '@/components/SandboxDocumentBadge';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import DocBadge from '@/components/ui/DocBadge';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatDateTime } from '@/utils/format';
import { deliveryNoteStatusTone } from '@/utils/labels';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

type Line = { id: number; description: string; product_name: string | null; variant_name: string | null; sku: string | null; reference: string | null; unit_label: string | null; quantity: string };
type Note = {
    sandbox_id?: number | null;
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

const docLink =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-field border border-line-strong bg-surface px-4 text-sm font-semibold text-ink shadow-xs transition-soft hover:bg-sage';

function Detail({ term, children }: { term: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3 py-2">
            <dt className="text-ink-muted">{term}</dt>
            <dd className="text-end font-medium text-ink">{children}</dd>
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
    const issuance = useForm({});
    const [emailModalOpen, setEmailModalOpen] = useState(false);
    const [confirmIssue, setConfirmIssue] = useState(false);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const update = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/delivery-notes/${note.id}`, { preserveScroll: true });
    };
    const issue = () => issuance.post(`/delivery-notes/${note.id}/issue`, { onSuccess: () => setConfirmIssue(false) });
    const cancel = (event: FormEvent) => {
        event.preventDefault();
        cancellation.post(`/delivery-notes/${note.id}/cancel`, { preserveScroll: true, onSuccess: () => setConfirmCancel(false) });
    };
    const title = note.delivery_note_number ?? t('deliveryNotes.draftNumber').replace('{id}', String(note.id));
    const isDraft = note.status === 'draft';
    const recipient = note.recipient_company ?? note.recipient_name ?? t('common.counterCustomer');

    return (
        <SalesLayout>
            <Head title={title} />

            {/* Identity + document actions */}
            <div className="mb-6">
                <ButtonLink href="/delivery-notes" variant="ghost" size="sm" className="-ms-2 mb-1">
                    <svg aria-hidden="true" className="directional-icon rtl:rotate-180" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M14 6 8 12l6 6" />
                    </svg>
                    {t('deliveryNotes.back')}
                </ButtonLink>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="me-1 min-w-0 text-2xl font-semibold tracking-[-0.02em] text-ink [overflow-wrap:anywhere] sm:text-page">
                                <bdi>{title}</bdi>
                            </h1>
                            <DocBadge tone={deliveryNoteStatusTone(note.status)}>{statusLabel[note.status] ?? note.status}</DocBadge>
                            <SandboxDocumentBadge sandboxId={note.sandbox_id} />
                        </div>
                        <p className="mt-1 text-sm text-ink-muted">
                            {t('deliveryNotes.order')}{' '}
                            <Link href={`/sales/orders/${note.sales_order.id}`} className="font-medium text-ink underline-offset-2 hover:text-accent hover:underline">
                                <bdi>{note.sales_order.order_number}</bdi>
                            </Link>{' '}
                            · <span className="font-medium text-ink">{recipient}</span> · {formatDate(note.delivery_date)}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={isDraft ? `/delivery-notes/${note.id}/print` : `/delivery-notes/${note.id}/pdf`} target="_blank" rel="noreferrer" className={docLink}>
                            {isDraft ? t('deliveryNotes.preview') : t('deliveryNotes.viewPdf')}
                        </a>
                        {note.status === 'issued' && (
                            <a href={`/delivery-notes/${note.id}/download`} className={docLink}>
                                {t('deliveryNotes.downloadPdf')}
                            </a>
                        )}
                        {note.status === 'issued' && can.email && (
                            <Button type="button" variant="secondary" onClick={() => setEmailModalOpen(true)}>
                                {t('deliveryNotes.email')}
                            </Button>
                        )}
                        {isDraft && can.issue && <Button onClick={() => setConfirmIssue(true)}>{t('deliveryNotes.issue')}</Button>}
                    </div>
                </div>
            </div>

            {note.status === 'cancelled' && (
                <p className="mb-6 rounded-card border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning" role="status">
                    {t('deliveryNotes.cancelledReason').replace('{reason}', note.cancellation_reason ?? t('deliveryNotes.noReason'))}
                </p>
            )}

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                {/* Details column */}
                <aside className="grid gap-4 sm:grid-cols-2 xl:col-start-2 xl:row-start-1 xl:grid-cols-1">
                    <section className="surface-card p-5">
                        <h2 className="text-base font-semibold text-ink">{t('deliveryNotes.issuance')}</h2>
                        <dl className="mt-2 divide-y divide-line text-sm">
                            <Detail term={t('deliveryNotes.deliveryDate')}>{formatDate(note.delivery_date)}</Detail>
                            <Detail term={t('deliveryNotes.store')}>
                                <bdi>{note.store.code}</bdi> · {note.store.name}
                            </Detail>
                            <Detail term={t('deliveryNotes.issuance')}>
                                {note.issued_at ? `${note.issued_by?.name ?? t('deliveryNotes.unknown')} · ${formatDateTime(note.issued_at)}` : t('deliveryNotes.notIssued')}
                            </Detail>
                        </dl>
                    </section>
                    {!isDraft && (
                        <section className="surface-card p-5">
                            <h2 className="text-base font-semibold text-ink">{t('deliveryNotes.recipientAtIssue')}</h2>
                            <p className="mt-2 text-sm font-medium text-ink">{recipient}</p>
                            {note.recipient_phone && (
                                <p className="text-sm text-ink-muted">
                                    <bdi>{note.recipient_phone}</bdi>
                                </p>
                            )}
                            {note.delivery_address && <p className="whitespace-pre-wrap text-sm text-ink-muted">{note.delivery_address}</p>}
                            {note.notes && <p className="mt-2 border-t border-line pt-2 text-sm whitespace-pre-wrap text-ink-muted">{note.notes}</p>}
                        </section>
                    )}
                    {isDraft && can.updateDraft && (
                        <section className="surface-card p-5">
                            <h2 className="text-base font-semibold text-ink">{t('deliveryNotes.cancelDraft')}</h2>
                            <p className="mt-1 mb-3 text-sm text-ink-muted">{t('deliveryNotes.cancelHelp')}</p>
                            <Button variant="danger" onClick={() => setConfirmCancel(true)}>
                                {t('deliveryNotes.cancelDraft')}
                            </Button>
                        </section>
                    )}
                </aside>

                {/* Main column */}
                <div className="min-w-0 space-y-6 xl:col-start-1 xl:row-start-1">
                    <section aria-label={t('deliveryNotes.item')} className="surface-card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="erp-table min-w-[560px] text-sm">
                                <thead>
                                    <tr>
                                        <th className="px-4 py-2.5">{t('deliveryNotes.item')}</th>
                                        <th className="px-4 py-2.5">{t('deliveryNotes.reference')}</th>
                                        <th className="num px-4 py-2.5">{t('deliveryNotes.deliveredQuantity')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {note.lines.map((line) => (
                                        <tr key={line.id}>
                                            <td className="px-4 py-3">
                                                <p className="font-medium text-ink">{line.product_name ?? line.description}</p>
                                                {(line.variant_name || line.sku) && <p className="text-[13px] text-ink-muted">{[line.variant_name, line.sku].filter(Boolean).join(' · ')}</p>}
                                            </td>
                                            <td className="px-4 py-3 text-ink-muted">
                                                <bdi>{line.reference ?? '—'}</bdi>
                                            </td>
                                            <td className="num px-4 py-3 font-semibold text-ink" dir="ltr">
                                                {line.quantity} {line.unit_label}
                                            </td>
                                        </tr>
                                    ))}
                                    {note.lines.length === 0 && (
                                        <tr>
                                            <td colSpan={3} className="px-4 py-8 text-center text-ink-muted">
                                                {t('deliveryNotes.noItems')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {isDraft && can.updateDraft && (
                        <form onSubmit={update} className="surface-card grid gap-4 p-5 md:grid-cols-2">
                            <h2 className="text-base font-semibold text-ink md:col-span-2">{t('deliveryNotes.draftInfo')}</h2>
                            <label className="block text-[13px] font-medium text-ink">
                                {t('deliveryNotes.deliveryDate')}
                                <input
                                    type="date"
                                    readOnly={!can.backdate}
                                    value={form.data.delivery_date}
                                    onChange={(e) => form.setData('delivery_date', e.target.value)}
                                    className="control-input mt-1.5 read-only:bg-raised"
                                />
                            </label>
                            <label className="block text-[13px] font-medium text-ink">
                                {t('deliveryNotes.recipientName')}
                                <input value={form.data.recipient_name} onChange={(e) => form.setData('recipient_name', e.target.value)} className="control-input mt-1.5" />
                            </label>
                            <label className="block text-[13px] font-medium text-ink">
                                {t('deliveryNotes.company')}
                                <input value={form.data.recipient_company} onChange={(e) => form.setData('recipient_company', e.target.value)} className="control-input mt-1.5" />
                            </label>
                            <label className="block text-[13px] font-medium text-ink">
                                {t('deliveryNotes.phone')}
                                <input value={form.data.recipient_phone} onChange={(e) => form.setData('recipient_phone', e.target.value)} className="control-input mt-1.5" />
                            </label>
                            <label className="block text-[13px] font-medium text-ink md:col-span-2">
                                {t('deliveryNotes.address')}
                                <textarea rows={2} value={form.data.delivery_address} onChange={(e) => form.setData('delivery_address', e.target.value)} className="control-base mt-1.5 px-3 py-2 text-sm" />
                            </label>
                            <label className="block text-[13px] font-medium text-ink md:col-span-2">
                                {t('deliveryNotes.notes')}
                                <textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className="control-base mt-1.5 px-3 py-2 text-sm" />
                            </label>
                            {Object.values(form.errors).map(
                                (error) =>
                                    error && (
                                        <p key={error} className="text-[13px] text-danger md:col-span-2" role="alert">
                                            {error}
                                        </p>
                                    ),
                            )}
                            <div className="md:col-span-2">
                                <Button type="submit" variant="secondary" loading={form.processing} loadingText={t('common.saving')}>
                                    {t('deliveryNotes.saveDraft')}
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            </div>

            {/* Issue: assigns the definitive number */}
            <Dialog
                open={confirmIssue}
                onClose={() => setConfirmIssue(false)}
                dismissible={!issuance.processing}
                size="sm"
                title={t('deliveryNotes.issue')}
                description={t('deliveryNotes.issueConfirmHelp')}
            >
                <div className="flex flex-wrap justify-end gap-2 p-4 sm:p-6">
                    <Button type="button" variant="secondary" onClick={() => setConfirmIssue(false)} disabled={issuance.processing}>
                        {t('common.back')}
                    </Button>
                    <Button type="button" data-autofocus loading={issuance.processing} loadingText={t('common.loading')} onClick={issue}>
                        {t('deliveryNotes.issue')}
                    </Button>
                </div>
            </Dialog>

            {/* Cancel draft: optional reason, explicit confirmation */}
            <Dialog
                open={confirmCancel}
                onClose={() => setConfirmCancel(false)}
                dismissible={!cancellation.processing}
                danger
                size="sm"
                title={t('deliveryNotes.cancelDraft')}
                description={`${title} · ${t('deliveryNotes.order')} ${note.sales_order.order_number}`}
            >
                <form onSubmit={cancel} className="space-y-4 p-4 sm:p-6">
                    <label htmlFor="dn-cancel-reason" className="block text-sm font-medium text-ink">
                        {t('deliveryNotes.reasonOptional')}
                    </label>
                    <textarea
                        id="dn-cancel-reason"
                        data-autofocus
                        value={cancellation.data.reason}
                        onChange={(e) => cancellation.setData('reason', e.target.value)}
                        className="control-base min-h-24 px-3 py-2 text-sm"
                    />
                    {Object.values(cancellation.errors).map((error) => error && <p key={error} className="text-sm text-danger">{error}</p>)}
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setConfirmCancel(false)} disabled={cancellation.processing}>
                            {t('common.back')}
                        </Button>
                        <Button type="submit" variant="danger-solid" loading={cancellation.processing}>
                            {t('deliveryNotes.cancelDraft')}
                        </Button>
                    </div>
                </form>
            </Dialog>

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
