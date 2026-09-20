import { Button } from '@/components/ui/Button';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatMoney, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm } from '@inertiajs/react';

type PaymentOption = {
    id: number;
    payment_number: string;
    method: string;
    allocated_amount: string;
    refundable_amount: string;
    suggested_amount: string;
    financial_account: { name: string; code: string; type: string } | null;
};

type Props = {
    customerReturn: any;
    creditSummary: { issued: string };
    refundSummary: { refunded: string; remaining: string };
    payments: PaymentOption[];
    can: { receive: boolean; cancel: boolean; refund: boolean };
};

export default function ReturnShow({ customerReturn: item, creditSummary, refundSummary, payments, can }: Props) {
    const cancel = useForm({ reason: '' });
    const refund = useForm({
        payment_id: payments[0]?.id ?? 0,
        amount: payments[0]?.suggested_amount ?? refundSummary.remaining,
        reason: '',
        client_operation_id: crypto.randomUUID(),
    });

    const selectPayment = (paymentId: number) => {
        const payment = payments.find((candidate) => candidate.id === paymentId);
        refund.setData((data) => ({ ...data, payment_id: paymentId, amount: payment?.suggested_amount ?? '' }));
    };

    return (
        <SalesLayout>
            <Head title={item.return_number} />
            <div className="mx-auto max-w-5xl space-y-6">
                <header className="flex flex-wrap justify-between gap-3">
                    <div>
                        <Link href="/sales/returns" className="text-sm text-ink-muted">← Retours</Link>
                        <h1 className="text-2xl font-semibold">{item.return_number}</h1>
                        <p className="text-sm text-ink-muted">
                            <Link href={`/sales/orders/${item.sales_order.id}`} className="hover:underline">Commande {item.sales_order.order_number}</Link>
                            {' · '}{item.sales_order.customer_name || 'Client comptoir'}
                        </p>
                    </div>
                    <span className="rounded-full bg-raised px-3 py-1 text-sm uppercase">{item.status}</span>
                </header>

                <section className="grid gap-3 rounded-card border border-line bg-surface p-5 sm:grid-cols-4">
                    <Summary label="Montant retourné" value={formatMoney(item.total_incl_tax, item.currency_code)} />
                    <Summary label="Avoir émis" value={formatMoney(creditSummary.issued, item.currency_code)} />
                    <Summary label="Remboursé" value={formatMoney(refundSummary.refunded, item.currency_code)} />
                    <Summary label="Reste à rembourser" value={formatMoney(refundSummary.remaining, item.currency_code)} />
                    <p className="sm:col-span-4 text-sm text-ink-muted">Disposition : {item.disposition === 'restock' ? 'Remise en stock vendable' : 'Endommagé / non vendable'} · Entrepôt : {item.warehouse.name}</p>
                </section>

                <section className="rounded-card border border-line bg-surface p-5">
                    <h2 className="font-semibold">Articles retournés</h2>
                    {item.lines.map((line: any) => <div key={line.id} className="mt-2 flex justify-between border-t border-line pt-2 text-sm"><span>{line.reference || line.sku} · {line.product_name}{line.variant_name ? ` — ${line.variant_name}` : ''}</span><span>{formatQuantity(line.quantity)} · {formatMoney(line.total_incl_tax, item.currency_code)}</span></div>)}
                </section>

                {item.credit_notes.length > 0 && <section className="rounded-card border border-line bg-surface p-5"><h2 className="font-semibold">Avoirs comptables</h2>{item.credit_notes.map((note: any) => <Link key={note.id} href={`/credit-notes/${note.id}`} className="mt-2 flex justify-between border-t border-line pt-2"><span>{note.credit_note_number || 'Brouillon'} · Facture {note.invoice.invoice_number}{note.invoice.version > 1 ? ` V${note.invoice.version}` : ''}</span><span>-{formatMoney(note.total_incl_tax, item.currency_code)}</span></Link>)}</section>}

                {item.refunds.length > 0 && <section className="rounded-card border border-line bg-surface p-5"><h2 className="font-semibold">Remboursements financiers</h2>{item.refunds.map((entry: any) => <div key={entry.id} className="mt-2 flex justify-between border-t border-line pt-2 text-sm"><span>{entry.refund_number} · {formatDate(entry.refund_date)} · Paiement {entry.payment.payment_number}<span className="block text-xs text-ink-muted">{entry.reason}</span></span><span>-{formatMoney(entry.amount, item.currency_code)}</span></div>)}</section>}

                {can.receive && <Button onClick={() => router.post(`/sales/returns/${item.id}/receive`)}>Réceptionner le retour</Button>}

                {can.refund && Number(refundSummary.remaining) > 0 && payments.length > 0 && <section className="space-y-3 rounded-card border border-line bg-surface p-5">
                    <div><h2 className="font-semibold">Rembourser le client</h2><p className="text-xs text-ink-muted">Choisissez explicitement le paiement et le compte depuis lesquels les fonds sont remboursés.</p></div>
                    <label className="block text-sm"><span className="mb-1 block text-ink-muted">Paiement source / compte</span><select value={refund.data.payment_id} onChange={(event) => selectPayment(Number(event.target.value))} className="w-full rounded-field border p-2">{payments.map((payment) => <option key={payment.id} value={payment.id}>{payment.payment_number} · {payment.method} · {payment.financial_account ? `${payment.financial_account.code} ${payment.financial_account.name}` : 'Compte indisponible'} · disponible {formatMoney(payment.refundable_amount, item.currency_code)}</option>)}</select></label>
                    <label className="block text-sm"><span className="mb-1 block text-ink-muted">Montant remboursé</span><input type="number" step="0.01" min="0.01" value={refund.data.amount} onChange={(event) => refund.setData('amount', event.target.value)} className="w-full rounded-field border p-2" /></label>
                    <textarea required value={refund.data.reason} onChange={(event) => refund.setData('reason', event.target.value)} placeholder="Motif du remboursement" className="w-full rounded-field border p-2" />
                    {Object.values(refund.errors).map((error) => error && <p key={error} className="text-sm text-danger">{error}</p>)}
                    <Button loading={refund.processing} onClick={() => refund.post(`/sales/returns/${item.id}/refunds`)}>Enregistrer le remboursement</Button>
                </section>}

                {can.cancel && <section className="rounded-card border border-danger/30 p-5"><textarea value={cancel.data.reason} onChange={(event) => cancel.setData('reason', event.target.value)} placeholder="Motif d’annulation" className="w-full rounded-field border p-2" /><Button variant="danger" loading={cancel.processing} onClick={() => cancel.post(`/sales/returns/${item.id}/cancel`)} className="mt-2">Annuler le retour brouillon</Button></section>}
            </div>
        </SalesLayout>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return <div><small className="text-ink-muted">{label}</small><strong className="block">{value}</strong></div>;
}
