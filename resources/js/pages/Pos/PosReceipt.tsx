import { formatDateTime, formatMoney, formatQuantity } from '@/utils/format';
import { useI18n } from '@/i18n';

type ReceiptPayment = { method: string; amount: string; reference: string | null; cash_received: string | null; change: string | null };
type ReceiptLine = { id: number; description: string; quantity: string; unit_price_incl_tax?: string; line_total?: string };

type Props = {
    storeName: string;
    order: {
        order_number: string;
        customer_name: string | null;
        ordered_at?: string;
        subtotal_excl_tax: string;
        discount_total: string;
        tax_total: string;
        total_incl_tax: string;
        currency_code: string;
        paid: string;
        remaining: string;
        payments: ReceiptPayment[];
    };
    lines: ReceiptLine[];
};

/**
 * 80mm thermal-style receipt. Hidden on screen; when the browser prints, only
 * this block is visible (see the scoped <style> below). Printing a receipt does
 * NOT create an invoice — the invoice is a separate, later step from the order.
 */
export default function PosReceipt({ storeName, order, lines }: Props) {
    const { direction, t } = useI18n();
    const currency = order.currency_code;
    const methodLabels: Record<string, string> = { cash: t('pos.cash'), card: t('pos.card'), bank_transfer: t('pos.bankTransfer'), cheque: t('pos.cheque') };

    return (
        <div id="pos-receipt" aria-hidden className="hidden print:block">
            <style>{`
                @media print {
                    body * { visibility: hidden !important; }
                    #pos-receipt, #pos-receipt * { visibility: visible !important; }
                    #pos-receipt { position: absolute; left: 0; top: 0; display: block !important; width: 80mm; padding: 4mm; }
                    @page { size: 80mm auto; margin: 0; }
                }
            `}</style>
            <div dir={direction} style={{ width: '72mm', fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', fontSize: '11px', color: '#000', lineHeight: 1.45 }}>
                <div style={{ textAlign: 'center', fontWeight: 700, fontSize: '13px' }}>{storeName}</div>
                <div style={{ textAlign: 'center' }}>{t('pos.receipt')} · <bdi>{order.order_number}</bdi></div>
                <div style={{ textAlign: 'center' }}>{order.ordered_at ? formatDateTime(order.ordered_at) : ''}</div>
                {order.customer_name && <div style={{ textAlign: 'center' }}>{t('pos.customer')} : {order.customer_name}</div>}
                <div style={{ borderTop: '1px dashed #000', margin: '6px 0' }} />
                {lines.map((line) => (
                    <div key={line.id} style={{ marginBottom: '2px' }}>
                        <div>{line.description}</div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                            <span>{formatQuantity(line.quantity)} × {formatMoney(line.unit_price_incl_tax ?? '0', currency)} {t('pos.vatIncluded')}</span>
                            <span>{formatMoney(line.line_total ?? '0', currency)}</span>
                        </div>
                    </div>
                ))}
                <div style={{ borderTop: '1px dashed #000', margin: '6px 0' }} />
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span>{t('pos.grossExcl')}</span>
                    <span>{formatMoney(order.subtotal_excl_tax, currency)}</span>
                </div>
                {Number(order.discount_total) > 0.00005 && (
                    <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                        <span>{t('pos.discountExcl')}</span>
                        <span>- {formatMoney(order.discount_total, currency)}</span>
                    </div>
                )}
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span>{t('pos.vat')}</span>
                    <span>{formatMoney(order.tax_total, currency)}</span>
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 700 }}>
                    <span>{t('pos.totalIncl')}</span>
                    <span>{formatMoney(order.total_incl_tax, currency)}</span>
                </div>
                {order.payments.map((payment, index) => (
                    <div key={index} style={{ display: 'flex', justifyContent: 'space-between' }}>
                        <span>{methodLabels[payment.method] ?? payment.method}{payment.reference ? ` (${payment.reference})` : ''}</span>
                        <span>{formatMoney(payment.amount, currency)}</span>
                    </div>
                ))}
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span>{t('pos.paid')}</span>
                    <span>{formatMoney(order.paid, currency)}</span>
                </div>
                {Number(order.remaining) > 0.00005 && (
                    <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                        <span>{t('pos.remainingShort')}</span>
                        <span>{formatMoney(order.remaining, currency)}</span>
                    </div>
                )}
                <div style={{ borderTop: '1px dashed #000', margin: '6px 0' }} />
                <div style={{ textAlign: 'center' }}>{t('pos.thanks')}</div><div style={{ textAlign: 'center', fontSize: '9px' }}>{t('pos.receiptNotInvoice')}</div>
            </div>
        </div>
    );
}
