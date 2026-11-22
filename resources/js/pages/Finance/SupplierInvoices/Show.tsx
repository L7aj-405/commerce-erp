import { ApproveDialog, InvoiceTotalsPanel, MatchingStatusBadge, VoidDialog, MatchingWarnings, SoftWarningList, SupplierInvoiceStatusBadge, TestModeRestrictionNotice } from '@/components/finance/supplier-invoices';
import type { ApprovePayload, SupplierInvoiceShowProps, VoidPayload } from '@/components/finance/supplier-invoices';
import { interpolate } from '@/components/finance/supplier-invoices/math';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/utils/format';
import { Head, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

const BASE = '/finance/factures-fournisseurs';
const formatSize = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

/** Read-only invoice detail. Edit / approve / void controls arrive with the write UI (PR12). */
export default function SupplierInvoiceShow(props: Partial<SupplierInvoiceShowProps>) {
    const { t } = useI18n();
    const page = usePage<{ sandbox?: { active: boolean } }>();
    const { invoice, matching, warnings = [], can } = props;
    const [dialog, setDialog] = useState<'approve' | 'void' | null>(null);
    const [processing, setProcessing] = useState(false);
    const [dialogErrors, setDialogErrors] = useState<Record<string, string>>({});
    const fileInput = useRef<HTMLInputElement>(null);

    // Wired against the A2 contract: POST {id}/approve and {id}/void; errors come back keyed by payload field.
    const send = (action: 'approve' | 'void', payload: ApprovePayload | VoidPayload) => {
        if (!invoice) return;
        setProcessing(true);
        setDialogErrors({});
        router.post(`${BASE}/${invoice.id}/${action}`, payload as unknown as Record<string, string | boolean | string[]>, {
            preserveScroll: true,
            onSuccess: () => setDialog(null),
            onError: (errors) => setDialogErrors(errors as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };
    // Attachment endpoints are owned by the attachment backend (PR6); paths follow the same prefix.
    const upload = (file: File | undefined) => {
        if (!file || !invoice) return;
        setProcessing(true);
        router.post(`${BASE}/${invoice.id}/attachments`, { file }, { forceFormData: true, preserveScroll: true, onFinish: () => { setProcessing(false); if (fileInput.current) fileInput.current.value = ''; } });
    };
    const removeAttachment = (id: number) => {
        if (invoice) router.delete(`${BASE}/${invoice.id}/attachments/${id}`, { preserveScroll: true });
    };

    const back = (
        <ButtonLink href={BASE} variant="secondary">
            {t('supplierInvoice.back')}
        </ButtonLink>
    );

    if (page.props.sandbox?.active) {
        return (
            <ApplicationShell wide>
                <Head title={t('supplierInvoice.title')} />
                <PageHeader title={t('supplierInvoice.title')} actions={back} />
                <TestModeRestrictionNotice />
            </ApplicationShell>
        );
    }

    if (!invoice) {
        return (
            <ApplicationShell wide>
                <Head title={t('supplierInvoice.title')} />
                <PageHeader title={t('supplierInvoice.title')} actions={back} />
                <EmptyState title={t('supplierInvoice.loadError')} actions={<Button variant="secondary" onClick={() => router.reload()}>{t('ui.retry')}</Button>} />
            </ApplicationShell>
        );
    }

    const c = invoice.currency_code;
    const positions = Object.fromEntries(invoice.lines.map((line) => [line.id, line.position]));
    const fact = (label: string, value: React.ReactNode) => (
        <div>
            <dt className="mb-1 text-[13px] text-ink-muted">{label}</dt>
            <dd className="text-ink">{value}</dd>
        </div>
    );
    const th = 'px-4 py-3';

    return (
        <ApplicationShell wide>
            <Head title={invoice.internal_number} />
            <PageHeader
                title={invoice.internal_number}
                description={`${invoice.supplier.name} · ${invoice.invoice_number}`}
                actions={
                    <>
                        <SupplierInvoiceStatusBadge status={invoice.status} />
                        {can?.edit && invoice.status === 'draft' && (
                            <ButtonLink href={`${BASE}/${invoice.id}/modifier`} variant="secondary">
                                {t('supplierInvoice.show.edit')}
                            </ButtonLink>
                        )}
                        {can?.approve && invoice.status === 'draft' && <Button onClick={() => setDialog('approve')}>{t('supplierInvoice.show.approve')}</Button>}
                        {can?.void && invoice.status !== 'void' && (
                            <Button variant="danger" onClick={() => setDialog('void')}>
                                {t('supplierInvoice.show.void')}
                            </Button>
                        )}
                        {back}
                    </>
                }
            />

            <section className="mb-6 surface-card p-5">
                <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    {fact(t('supplierInvoice.show.supplier'), <>{invoice.supplier.name}{invoice.supplier.ice && <span className="block text-[12.5px] text-ink-muted">ICE <bdi>{invoice.supplier.ice}</bdi></span>}</>)}
                    {fact(t('supplierInvoice.col.invoiceNumber'), <bdi>{invoice.invoice_number}</bdi>)}
                    {fact(t('supplierInvoice.show.invoiceDate'), formatDate(invoice.invoice_date))}
                    {fact(t('supplierInvoice.show.store'), invoice.store.name)}
                    {invoice.notes && <div className="sm:col-span-2 lg:col-span-4">{fact(t('supplierInvoice.show.notes'), invoice.notes)}</div>}
                </dl>
            </section>

            {warnings.length > 0 && (
                <div className="mb-6">
                    <SoftWarningList warnings={warnings} />
                </div>
            )}

            <section aria-labelledby="si-lines" className="mb-6 overflow-hidden surface-card">
                <h2 id="si-lines" className="border-b border-line px-4 py-3 text-base font-semibold">{t('supplierInvoice.show.lines')}</h2>
                {invoice.lines.length === 0 ? (
                    <p className="p-4 text-sm text-ink-muted">{t('supplierInvoice.show.noLines')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="erp-table min-w-[760px] text-sm">
                            <thead>
                                <tr>
                                    <th className={th}>#</th>
                                    <th className={th}>{t('supplierInvoice.col.description')}</th>
                                    <th className={`${th} text-end`}>{t('supplierInvoice.col.quantity')}</th>
                                    <th className={`${th} text-end`}>{t('supplierInvoice.col.unitHt')}</th>
                                    <th className={`${th} text-end`}>{t('supplierInvoice.col.tax')}</th>
                                    <th className={`${th} text-end`}>{t('supplierInvoice.col.lineHt')}</th>
                                    <th className={`${th} text-end`}>{t('supplierInvoice.col.lineTtc')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.lines.map((line) => (
                                    <tr key={line.id}>
                                        <td className={th}>{line.position}</td>
                                        <td className={th}>
                                            {line.description}
                                            {line.product_variant && <span className="block text-[12.5px] text-ink-muted">{line.product_variant.product_name ?? line.product_variant.label}{line.product_variant.sku ? ` · ${line.product_variant.sku}` : ''}</span>}
                                        </td>
                                        <td className={`${th} text-end tabular-nums`}>{formatQuantity(line.quantity)}</td>
                                        <td className={`${th} text-end tabular-nums`}>{formatMoney(line.unit_price_ht, c)}</td>
                                        <td className={`${th} text-end tabular-nums`}>{formatQuantity(line.tax_rate_percent)} %</td>
                                        <td className={`${th} text-end tabular-nums`}>{formatMoney(line.line_ht, c)}</td>
                                        <td className={`${th} text-end font-medium tabular-nums`}>{formatMoney(line.line_ttc, c)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <div className="mb-6 grid gap-4 lg:grid-cols-2">
                <InvoiceTotalsPanel subtotalHt={invoice.subtotal_ht} totalTva={invoice.total_tva} roundingAdjustment={invoice.rounding_adjustment} totalTtc={invoice.total_ttc} controlTotalTtc={invoice.control_total_ttc} currencyCode={c} />
                {matching && <MatchingWarnings matching={matching} positions={positions} />}
            </div>

            <section aria-labelledby="si-installments" className="mb-6 overflow-hidden surface-card">
                <h2 id="si-installments" className="border-b border-line px-4 py-3 text-base font-semibold">{t('supplierInvoice.show.installments')}</h2>
                {invoice.installments.length === 0 ? (
                    <p className="p-4 text-sm text-ink-muted">{t('supplierInvoice.show.noInstallments')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="erp-table min-w-[760px] text-sm">
                            <thead>
                                <tr>
                                    <th className={th}>{t('supplierInvoice.col.dueDate')}</th>
                                    <th className={`${th} text-end`}>{t('supplierInvoice.installments.amount')}</th>
                                    <th className={th}>{t('supplierInvoice.col.obligation')}</th>
                                    <th className={`${th} text-end`}>{t('supplierInvoice.col.paid')}</th>
                                    <th className={`${th} text-end`}>{t('supplierInvoice.col.remaining')}</th>
                                    <th className={th}>{t('supplierInvoice.col.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.installments.map((row) => (
                                    <tr key={row.id}>
                                        <td className={th}>{formatDate(row.due_date)}</td>
                                        <td className={`${th} text-end tabular-nums`}>{formatMoney(row.amount, c)}</td>
                                        {row.obligation ? (
                                            <>
                                                <td className={th}><bdi>{row.obligation.number}</bdi></td>
                                                <td className={`${th} text-end tabular-nums`}>{formatMoney(row.obligation.paid, c)}</td>
                                                <td className={`${th} text-end tabular-nums`}>{formatMoney(row.obligation.remaining, c)}</td>
                                                <td className={th}><StatusBadge status={row.obligation.status} /></td>
                                            </>
                                        ) : (
                                            <td className={`${th} text-ink-muted`} colSpan={4}>{t('supplierInvoice.show.noObligation')}</td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section aria-labelledby="si-attachments" className="mb-6 surface-card p-5">
                <h2 id="si-attachments" className="mb-3 text-base font-semibold">{t('supplierInvoice.show.attachments')}</h2>
                {invoice.attachments.length === 0 ? (
                    <p className="text-sm text-ink-muted">{t('supplierInvoice.show.noAttachments')}</p>
                ) : (
                    <ul className="space-y-1.5 text-sm">
                        {invoice.attachments.map((file) => (
                            <li key={file.id}>
                                {file.url ? <a href={file.url} className="font-medium text-ink underline hover:text-accent" target="_blank" rel="noopener noreferrer">{file.original_name}</a> : <span className="font-medium text-ink">{file.original_name}</span>}
                                <span className="ms-2 text-[12.5px] text-ink-muted">{formatSize(file.size_bytes)}</span>
                                {can?.attach && invoice.status === 'draft' && (
                                    <Button type="button" variant="ghost" size="sm" className="ms-2" onClick={() => removeAttachment(file.id)} aria-label={interpolate(t('supplierInvoice.attach.removeLabel'), { name: file.original_name })}>
                                        {t('supplierInvoice.attach.remove')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {can?.attach && (
                    <div className="mt-4">
                        <input ref={fileInput} id="si-attach-input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className="sr-only" onChange={(e) => upload(e.target.files?.[0])} />
                        <Button type="button" variant="secondary" size="sm" loading={processing} loadingText={t('supplierInvoice.attach.uploading')} onClick={() => fileInput.current?.click()}>
                            {t('supplierInvoice.attach.upload')}
                        </Button>
                        <p className="mt-1 text-[12.5px] text-ink-muted">{t('supplierInvoice.attach.hint')}</p>
                    </div>
                )}
            </section>

            {invoice.approval && (
                <section aria-labelledby="si-approval" className="mb-6 surface-card p-5">
                    <h2 id="si-approval" className="mb-3 text-base font-semibold">{t('supplierInvoice.show.approval')}</h2>
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        {fact(
                            interpolate(t('supplierInvoice.show.approvedOn'), { date: formatDateTime(invoice.approval.approved_at) }),
                            invoice.approval.approved_by ? interpolate(t('supplierInvoice.show.by'), { user: invoice.approval.approved_by }) : '—',
                        )}
                        {fact(t('supplierInvoice.show.matchAtApproval'), <MatchingStatusBadge status={invoice.approval.match_status_at_approval} />)}
                        {invoice.approval.match_note && <div className="sm:col-span-2">{fact(t('supplierInvoice.show.matchNote'), invoice.approval.match_note)}</div>}
                    </dl>
                </section>
            )}

            {invoice.void && (
                <section aria-labelledby="si-void" className="mb-6 surface-card border-danger/30 p-5">
                    <h2 id="si-void" className="mb-3 text-base font-semibold text-danger">{t('supplierInvoice.show.voidInfo')}</h2>
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        {fact(
                            interpolate(t('supplierInvoice.show.voidedOn'), { date: formatDateTime(invoice.void.voided_at) }),
                            invoice.void.voided_by ? interpolate(t('supplierInvoice.show.by'), { user: invoice.void.voided_by }) : '—',
                        )}
                        {fact(t('supplierInvoice.show.voidReason'), <>{t(`supplierInvoice.void.reason.${invoice.void.reason_code}`)}{invoice.void.reason && <span className="block text-ink-muted">{invoice.void.reason}</span>}</>)}
                    </dl>
                </section>
            )}
            {dialog === 'approve' && (
                <ApproveDialog
                    open
                    onClose={() => setDialog(null)}
                    onConfirm={(payload) => send('approve', payload)}
                    totalTtc={invoice.total_ttc}
                    currencyCode={c}
                    initialControlTotal={invoice.control_total_ttc}
                    matchStatus={matching?.status ?? 'no_po'}
                    warnings={warnings}
                    processing={processing}
                    errors={dialogErrors}
                />
            )}
            {dialog === 'void' && <VoidDialog open onClose={() => setDialog(null)} onConfirm={(payload) => send('void', payload)} processing={processing} errors={dialogErrors} />}
        </ApplicationShell>
    );
}
