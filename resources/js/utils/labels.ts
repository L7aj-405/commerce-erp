/**
 * French operational labels for the Sales / Invoice UI. Backend enums are
 * unchanged — these map the raw values to what an operator reads.
 */

export type BadgeTone = 'neutral' | 'positive' | 'warning' | 'danger' | 'info';

export const orderStatusLabel: Record<string, string> = {
    draft: 'Brouillon',
    confirmed: 'Confirmée',
    cancelled: 'Annulée',
};

export const fulfillmentLabel: Record<string, string> = {
    unfulfilled: 'À préparer',
    partially_fulfilled: 'Préparation partielle',
    fulfilled: 'Expédiée',
};

export const paymentStatusLabel: Record<string, string> = {
    unpaid: 'Non payée',
    partially_paid: 'Partiellement payée',
    paid: 'Payée',
};

export const sourceLabel: Record<string, string> = {
    pos: 'POS',
    manual: 'Manuel',
    ecommerce: 'E-commerce',
};

export const paymentMethodLabel: Record<string, string> = {
    cash: 'Espèces',
    card: 'TPE',
    bank_transfer: 'Virement',
    cheque: 'Chèque',
};

export const paymentEntryStatusLabel: Record<string, string> = {
    posted: 'Encaissé',
    reversed: 'Annulé',
};

export const invoiceStatusLabel: Record<string, string> = {
    draft: 'Brouillon',
    issued: 'Émise',
    cancelled: 'Annulée',
    superseded: 'Remplacée',
};

export function orderStatusTone(value: string): BadgeTone {
    return value === 'confirmed' ? 'positive' : value === 'cancelled' ? 'danger' : 'neutral';
}

export function fulfillmentTone(value: string): BadgeTone {
    return value === 'fulfilled' ? 'positive' : value === 'partially_fulfilled' ? 'warning' : 'neutral';
}

export function paymentStatusTone(value: string): BadgeTone {
    return value === 'paid' ? 'positive' : value === 'partially_paid' ? 'warning' : value === 'unpaid' ? 'danger' : 'neutral';
}

export function invoiceStatusTone(value: string): BadgeTone {
    if (value === 'issued') return 'positive';
    if (value === 'cancelled') return 'danger';
    if (value === 'superseded') return 'warning';
    return 'neutral';
}

export const quotationStatusLabel: Record<string, string> = {
    draft: 'Brouillon',
    issued: 'Émis',
    accepted: 'Accepté',
    rejected: 'Refusé',
    expired: 'Expiré',
    converted: 'Transformé',
    superseded: 'Remplacé',
};

export function quotationStatusTone(value: string): BadgeTone {
    if (value === 'accepted' || value === 'converted') return 'positive';
    if (value === 'issued') return 'info';
    if (value === 'rejected') return 'danger';
    if (value === 'expired' || value === 'superseded') return 'warning';
    return 'neutral';
}

export const deliveryNoteStatusLabel: Record<string, string> = {
    draft: 'Brouillon',
    issued: 'Émis',
    cancelled: 'Annulé',
};

export function deliveryNoteStatusTone(value: string): BadgeTone {
    if (value === 'issued') return 'positive';
    if (value === 'cancelled') return 'danger';
    return 'neutral';
}

const arabicLabels = new Map<Record<string, string>, Record<string, string>>([
    [orderStatusLabel, { draft: 'مسودة', confirmed: 'مؤكدة', cancelled: 'ملغاة' }],
    [fulfillmentLabel, { unfulfilled: 'بانتظار التحضير', partially_fulfilled: 'محضّرة جزئياً', fulfilled: 'تم الشحن' }],
    [paymentStatusLabel, { unpaid: 'غير مؤداة', partially_paid: 'مؤداة جزئياً', paid: 'مؤداة' }],
    [sourceLabel, { pos: 'نقطة البيع', manual: 'يدوي', ecommerce: 'تجارة إلكترونية' }],
    [paymentMethodLabel, { cash: 'نقداً', card: 'بطاقة / TPE', bank_transfer: 'تحويل بنكي', cheque: 'شيك' }],
    [paymentEntryStatusLabel, { posted: 'مُحصّل', reversed: 'ملغى' }],
    [invoiceStatusLabel, { draft: 'مسودة', issued: 'صادرة', cancelled: 'ملغاة', superseded: 'مستبدلة' }],
    [quotationStatusLabel, { draft: 'مسودة', issued: 'صادر', accepted: 'مقبول', rejected: 'مرفوض', expired: 'منتهي الصلاحية', converted: 'محوّل', superseded: 'مستبدل' }],
    [deliveryNoteStatusLabel, { draft: 'مسودة', issued: 'صادر', cancelled: 'ملغى' }],
]);

/** `label` helper: read a map, fall back to a de-underscored raw value. */
export function label(map: Record<string, string>, value: string | null | undefined): string {
    if (!value) return '—';
    const localizedMap = typeof document !== 'undefined' && document.documentElement.lang === 'ar' ? arabicLabels.get(map) : undefined;
    return localizedMap?.[value] ?? map[value] ?? value.replaceAll('_', ' ');
}
