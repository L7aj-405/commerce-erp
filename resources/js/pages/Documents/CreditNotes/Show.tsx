import { ButtonLink } from '@/components/ui/Button';
import SalesLayout from '@/layouts/SalesLayout';
import SandboxDocumentBadge from '@/components/SandboxDocumentBadge';
import { useI18n } from '@/i18n';
import { formatDate, formatMoney, formatQuantity } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type CreditNoteLine = {
    id: number;
    reference: string | null;
    description: string;
    quantity: string;
    unit_price_excl_tax: string;
    subtotal_excl_tax: string;
    discount_amount: string;
    taxable_amount: string;
    tax_rate: string;
    tax_amount: string;
    total_incl_tax: string;
    customer_return_line: { sku: string | null; variant_name: string | null } | null;
};

type Props = {
    summary: { net_excl_tax: string };
    creditNote: {
        id: number;
        sandbox_id?: number | null;
        status: string;
        credit_note_number: string | null;
        credit_note_date: string;
        currency_code: string;
        reason: string;
        subtotal_excl_tax: string;
        discount_total: string;
        tax_total: string;
        total_incl_tax: string;
        invoice: { id: number; invoice_number: string; version: number };
        sales_order: { id: number; order_number: string };
        customer_return: { id: number; return_number: string };
        lines: CreditNoteLine[];
    };
};

export default function CreditNoteShow({ creditNote: note, summary }: Props) {
    const { t } = useI18n();
    return (
        <SalesLayout>
            <Head title={note.credit_note_number || `${t('creditNotes.title')} ${t('creditNotes.draft')}`} />
            <div className="space-y-6">
                <header className="flex flex-wrap justify-between gap-3">
                    <div>
                        <ButtonLink href={`/invoices/${note.invoice.id}`} variant="ghost" size="sm" className="-ms-2 mb-1">
                            <svg aria-hidden="true" className="directional-icon rtl:rotate-180" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 6 8 12l6 6" /></svg>
                            {t('invoices.invoice')} <bdi>{note.invoice.invoice_number}</bdi>{note.invoice.version > 1 ? ` · V${note.invoice.version}` : ''}
                        </ButtonLink>
                        <h1 className="flex flex-wrap items-center gap-2 text-2xl font-semibold tracking-[-0.02em] text-ink sm:text-page">{t('creditNotes.title')} <bdi>{note.credit_note_number || t('creditNotes.draft')}</bdi> <SandboxDocumentBadge sandboxId={note.sandbox_id} /></h1>
                        <p className="text-sm text-ink-muted">
                            <Link href={`/sales/orders/${note.sales_order.id}`} className="font-medium text-ink underline-offset-2 hover:text-accent hover:underline">{t('invoices.order')} <bdi>{note.sales_order.order_number}</bdi></Link>
                            {' · '}
                            <Link href={`/sales/returns/${note.customer_return.id}`} className="font-medium text-ink underline-offset-2 hover:text-accent hover:underline">{t('creditNotes.return')} <bdi>{note.customer_return.return_number}</bdi></Link>
                        </p>
                    </div>
                    {note.status === 'issued' && (
                        <a href={`/credit-notes/${note.id}/pdf`} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center justify-center gap-2 self-start rounded-field bg-primary px-4 text-sm font-semibold text-primary-fg shadow-sm transition-soft hover:bg-primary-hover">
                            {t('creditNotes.officialPdf')}
                        </a>
                    )}
                </header>

                <section className="surface-card p-5 text-sm">
                    <p><span className="text-ink-muted">{t('creditNotes.issueDate')} :</span> {formatDate(note.credit_note_date)}</p>
                    <p className="mt-1"><span className="text-ink-muted">{t('creditNotes.reason')} :</span> {note.reason}</p>
                    <p className="mt-3 rounded-field bg-raised px-3 py-2 text-xs text-ink-muted">
                        {t('creditNotes.accountingNotice')}
                    </p>
                </section>

                <div className="overflow-x-auto surface-card">
                    <table className="erp-table w-full text-sm">
                        <thead className="bg-raised text-start text-[13px] text-ink-muted font-medium">
                            <tr><th className="p-3">{t('quotations.item')}</th><th className="p-3">{t('quotations.quantityShort')}</th><th className="p-3 text-end">{t('creditNotes.netExcl')}</th><th className="p-3 text-end">{t('creditNotes.discount')}</th><th className="p-3 text-end">{t('quotations.tax')}</th><th className="p-3 text-end">{t('creditNotes.creditedIncl')}</th></tr>
                        </thead>
                        <tbody>{note.lines.map((line) => (
                            <tr key={line.id} className="border-t border-line">
                                <td className="p-3"><strong>{line.reference || line.customer_return_line?.sku || '—'}</strong><br />{line.description}{line.customer_return_line?.variant_name && <span className="block text-xs text-ink-muted">{line.customer_return_line.variant_name}</span>}</td>
                                <td className="p-3">{formatQuantity(line.quantity)}</td>
                                <td className="p-3 text-end">{formatMoney(line.taxable_amount, note.currency_code)}</td>
                                <td className="p-3 text-end">{formatMoney(line.discount_amount, note.currency_code)}</td>
                                <td className="p-3 text-end">{formatQuantity(line.tax_rate)}% · {formatMoney(line.tax_amount, note.currency_code)}</td>
                                <td className="p-3 text-end font-medium">-{formatMoney(line.total_incl_tax, note.currency_code)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>

                <dl className="ms-auto max-w-sm space-y-2 rounded-card bg-raised p-5 text-sm">
                    <Total label={t('creditNotes.subtotalExcl')} value={`-${formatMoney(note.subtotal_excl_tax, note.currency_code)}`} />
                    {Number(note.discount_total) !== 0 && <Total label={t('creditNotes.discounts')} value={formatMoney(note.discount_total, note.currency_code)} />}
                    <Total label={t('creditNotes.totalNetExcl')} value={`-${formatMoney(summary.net_excl_tax, note.currency_code)}`} />
                    <Total label={t('quotations.tax')} value={`-${formatMoney(note.tax_total, note.currency_code)}`} />
                    <div className="flex justify-between border-t border-line-strong pt-2 text-lg font-semibold"><dt>{t('quotations.totalTaxIncluded')}</dt><dd>-{formatMoney(note.total_incl_tax, note.currency_code)}</dd></div>
                </dl>
            </div>
        </SalesLayout>
    );
}

function Total({ label, value }: { label: string; value: string }) {
    return <div className="flex justify-between"><dt className="text-ink-muted">{label}</dt><dd>{value}</dd></div>;
}
