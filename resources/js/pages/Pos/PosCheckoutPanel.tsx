import { Money, NumericInput, SegmentedControl } from '@/components/pos/primitives';
import SandboxPaymentBadge from '@/components/SandboxPaymentBadge';
import { Alert } from '@/components/ui/Alert';
import { Button, IconButton } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { useI18n } from '@/i18n';
import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import PosCustomerCard from './PosCustomerCard';
import { PlusIcon } from './PosCustomerDialog';
import {
    type Account,
    type CheckoutState,
    type FulfillmentOrigin,
    type PaymentDraft,
    type PaymentMethod,
    type ReviewData,
    type SettlementMode,
    buildEmptyPayment,
    buildInitialPayment,
    compatibleAccounts,
    defaultOriginFor,
    firstCompatibleAccountId,
    normalizeAmount,
    positiveAmount,
} from './pos-shared';
import type { ActiveSale } from './types';

type Props = {
    sale: ActiveSale;
    currencyCode: string;
    financialAccounts: Account[];
    canUpdateCustomer: boolean;
    /** A fulfilment/shipping write to the draft is in flight. */
    savingCheckout: boolean;
    /** The selected customer is being attached to / detached from the draft. */
    attachingCustomer: boolean;
    onBack: () => void;
    /** Opens the shared customer dialog (search, or edit the attached customer). */
    onOpenCustomer: (view: 'search' | 'edit') => void;
    onCustomerDetach: () => void;
    onCheckoutStateSave: (state: CheckoutState) => Promise<void>;
    onReview: (data: ReviewData) => void;
};

function Section({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
    return (
        <section className="border-t border-line px-4 py-3.5 first:border-t-0">
            <div className="mb-2.5 flex min-h-8 items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-ink-muted">{title}</h3>
                {action}
            </div>
            {children}
        </section>
    );
}

const fieldClass = 'control-input h-10 text-sm';

/** Selectable option tile (fulfilment mode / origin). Selection = border + fill + check mark, never color alone. */
function ChoiceTile({ selected, title, help, icon, onClick, role, ariaDisabled, tooltip }: {
    selected: boolean;
    title: string;
    help: string;
    icon: ReactNode;
    onClick: () => void;
    role?: 'radio';
    ariaDisabled?: boolean;
    tooltip?: string;
}) {
    return (
        <button
            type="button"
            role={role}
            aria-checked={role ? selected : undefined}
            aria-pressed={role ? undefined : selected}
            aria-disabled={ariaDisabled}
            title={tooltip}
            onClick={onClick}
            className={`relative flex min-h-16 cursor-pointer flex-col items-start rounded-field border p-2.5 pe-7 text-start transition-soft ${
                selected ? 'border-primary bg-sage shadow-[inset_0_0_0_1px_var(--color-primary)]' : 'border-line-strong bg-surface hover:bg-sage'
            } ${ariaDisabled ? 'cursor-not-allowed opacity-55' : ''}`}
        >
            <span className="flex items-center gap-1.5 text-[13px] font-semibold text-ink">
                <span aria-hidden="true" className="text-ink-muted">{icon}</span>
                {title}
            </span>
            <span className="mt-0.5 block text-[12px] leading-4 text-ink-muted">{help}</span>
            {selected && (
                <span aria-hidden="true" className="absolute end-2 top-2 grid size-4 place-items-center rounded-full bg-primary text-primary-fg">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.5"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                </span>
            )}
        </button>
    );
}

const svgIcon = (path: ReactNode) => (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">{path}</svg>
);
const icons = {
    pickup: svgIcon(<><path d="M5 8h14l-1 12H6L5 8Z" /><path d="M9 8a3 3 0 0 1 6 0" /></>),
    delivery: svgIcon(<><path d="M3 6h11v10H3V6ZM14 10h4l3 3v3h-7v-6Z" /><circle cx="7" cy="18" r="2" /><circle cx="18" cy="18" r="2" /></>),
    showroom: svgIcon(<path d="M4 9h16l-1-5H5L4 9ZM5 9v11h14V9M9 20v-6h6v6" />),
    direct: svgIcon(<><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M4 7l8 4 8-4M12 11v10" /></>),
};

/** One-sentence consequence of the chosen origin, shown before confirming. */
export function originExplanation(
    origin: FulfillmentOrigin,
    remote: boolean,
    remoteCount: string,
    showroom: string,
    t: (key: 'pos.originShowroomRemote' | 'pos.originShowroomLocal' | 'pos.originDirectRemote' | 'pos.originDirectLocal') => string,
): string {
    const key = origin === 'showroom'
        ? (remote ? 'pos.originShowroomRemote' : 'pos.originShowroomLocal')
        : (remote ? 'pos.originDirectRemote' : 'pos.originDirectLocal');
    return t(key).replace('{count}', remoteCount).replace('{warehouse}', showroom);
}

export default function PosCheckoutPanel({
    sale,
    currencyCode,
    financialAccounts,
    canUpdateCustomer,
    savingCheckout,
    attachingCustomer,
    onBack,
    onOpenCustomer,
    onCustomerDetach,
    onCheckoutStateSave,
    onReview,
}: Props) {
    const { direction, t } = useI18n();
    const paymentLabels: Record<PaymentMethod, string> = {
        cash: t('pos.cash'), card: t('pos.card'), bank_transfer: t('pos.bankTransfer'), cheque: t('pos.cheque'),
    };
    const referenceLabels: Record<PaymentMethod, string> = {
        cash: '', card: t('pos.cardReference'), bank_transfer: t('pos.transferReference'), cheque: t('pos.chequeNumber'),
    };
    const settlementLabels: Record<SettlementMode, string> = {
        full: t('pos.fullPayment'), partial: t('pos.partialPayment'), deferred: t('pos.deferredPayment'),
    };
    const [checkoutState, setCheckoutState] = useState<CheckoutState>({
        fulfillment_mode: sale.checkout.fulfillment_mode,
        fulfillment_origin: sale.checkout.fulfillment_origin ?? defaultOriginFor(sale.checkout.fulfillment_mode),
        shipping_fee: sale.checkout.shipping_fee,
        delivery_address: sale.checkout.delivery_address ?? '',
        delivery_phone: sale.checkout.delivery_phone ?? '',
        delivery_notes: sale.checkout.delivery_notes ?? '',
    });
    const [settlementMode, setSettlementMode] = useState<SettlementMode>('full');
    const [payments, setPayments] = useState<PaymentDraft[]>(() => [buildInitialPayment(sale.summary.total, financialAccounts)]);
    const [error, setError] = useState('');
    /** Every blocking payment problem found by the last "review" attempt (shown together). */
    const [paymentIssues, setPaymentIssues] = useState<string[]>([]);
    const [attempted, setAttempted] = useState(false);
    const [savingState, setSavingState] = useState(false);

    const selectedCustomer = sale.customer;

    // Authoritative merchandise TTC (net HT + VAT, after item + global discounts), from the server.
    const merchandiseNetTtc = Number(sale.summary.net_excl_tax) + Number(sale.summary.tax_total);
    const discountTotal = Number(sale.summary.line_discount_total) + Number(sale.summary.global_discount_amount);
    // Shipping is edited locally for instant feedback; it is a VAT-free amount (HT === TTC).
    const shippingNumeric = checkoutState.fulfillment_mode === 'delivery' ? Math.max(0, Number(checkoutState.shipping_fee) || 0) : 0;
    const currentTotal = useMemo(() => Math.max(0, merchandiseNetTtc + shippingNumeric).toFixed(4), [merchandiseNetTtc, shippingNumeric]);
    const paid = useMemo(() => payments.reduce((carry, payment) => carry + positiveAmount(payment.amount), 0), [payments]);
    const remaining = useMemo(() => Math.max(0, Number(currentTotal) - paid).toFixed(4), [currentTotal, paid]);

    const cashErrors = payments
        .map((payment, index) => {
            if (payment.method !== 'cash' || positiveAmount(payment.amount) <= 0) return null;
            if (!payment.cash_received) return t('pos.cashReceivedRequired').replace('{number}', String(index + 1));
            if (positiveAmount(payment.cash_received) < positiveAmount(payment.amount)) return t('pos.cashReceivedTooLow').replace('{number}', String(index + 1));
            return null;
        })
        .filter((message): message is string => message !== null);
    const accountErrors = payments
        .map((payment, index) => {
            if (!payment.method) return null;
            const compatible = compatibleAccounts(payment.method, financialAccounts);
            if (compatible.length === 0) return t('pos.accountMissingForMethod').replace('{number}', String(index + 1)).replace('{method}', paymentLabels[payment.method]);
            if (compatible.length > 1 && payment.financial_account_id === '') return t('pos.selectReceiptAccount').replace('{number}', String(index + 1));
            return null;
        })
        .filter((message): message is string => message !== null);

    async function saveCheckoutState(next = checkoutState) {
        setSavingState(true);
        setError('');
        try {
            await onCheckoutStateSave({
                fulfillment_mode: next.fulfillment_mode,
                fulfillment_origin: next.fulfillment_origin,
                shipping_fee: next.fulfillment_mode === 'delivery' ? next.shipping_fee || '0' : '0',
                delivery_address: next.fulfillment_mode === 'delivery' ? next.delivery_address : '',
                delivery_phone: next.fulfillment_mode === 'delivery' ? next.delivery_phone : '',
                delivery_notes: next.fulfillment_mode === 'delivery' ? next.delivery_notes : '',
            });
        } catch {
            setError(t('pos.fulfillmentUpdateFailed'));
        } finally {
            setSavingState(false);
        }
    }

    function setFulfillment(mode: 'pickup' | 'delivery') {
        const next: CheckoutState = mode === 'pickup'
            ? { fulfillment_mode: 'pickup', fulfillment_origin: 'showroom', shipping_fee: '0', delivery_address: '', delivery_phone: '', delivery_notes: '' }
            : { ...checkoutState, fulfillment_mode: 'delivery', fulfillment_origin: defaultOriginFor('delivery') };
        setError('');
        setCheckoutState(next);
        void saveCheckoutState(next);
    }

    function setOrigin(origin: FulfillmentOrigin) {
        // A pickup is handed over at the Showroom counter: direct dispatch is
        // refused with an explanation, never silently switched.
        if (checkoutState.fulfillment_mode === 'pickup' && origin === 'direct') {
            setError(t('pos.originPickupDirectBlocked'));
            return;
        }
        if (origin === checkoutState.fulfillment_origin) return;
        const next: CheckoutState = { ...checkoutState, fulfillment_origin: origin };
        setError('');
        setCheckoutState(next);
        void saveCheckoutState(next);
    }

    function patchPayment(index: number, patch: Partial<PaymentDraft>) {
        setPayments((current) => current.map((payment, itemIndex) => (itemIndex === index ? { ...payment, ...patch } : payment)));
    }

    function setSettlement(mode: SettlementMode) {
        setSettlementMode(mode);
        setError('');
        setPaymentIssues([]);
        setAttempted(false);
        if (mode === 'full') {
            setPayments([buildInitialPayment(currentTotal, financialAccounts)]);
        } else if (mode === 'partial') {
            setPayments([buildEmptyPayment()]);
        } else {
            setPayments([]);
        }
    }

    function assignMethod(index: number, method: PaymentMethod | '') {
        setPayments((current) =>
            current.map((payment, itemIndex) => {
                if (itemIndex !== index) return payment;
                const compatible = compatibleAccounts(method, financialAccounts);
                const stillCompatible = compatible.some((account) => account.id === payment.financial_account_id);
                const allocatedElsewhere = current.reduce((carry, row, rowIndex) => (rowIndex === index ? carry : carry + positiveAmount(row.amount)), 0);
                const suggestion = Math.max(0, Number(currentTotal) - allocatedElsewhere).toFixed(4);
                const amount = payment.amountTouched ? payment.amount : method ? normalizeAmount(suggestion) : '';
                return {
                    ...payment,
                    method,
                    financial_account_id: method ? (stillCompatible ? payment.financial_account_id : firstCompatibleAccountId(method, financialAccounts)) : '',
                    amount,
                    cash_received: method === 'cash' ? (payment.cashReceivedTouched ? payment.cash_received : amount) : '',
                    cashReceivedTouched: method === 'cash' ? payment.cashReceivedTouched : false,
                };
            }),
        );
    }

    function submitReview() {
        setAttempted(true);
        if (settlementMode !== 'deferred') {
            // Same rules and order as before; all failing rules are now listed together.
            const messages = [...cashErrors, ...accountErrors];
            if (paid <= 0) messages.push(t('pos.selectPayment'));
            else if (settlementMode === 'full' && Math.abs(Number(currentTotal) - paid) > 0.00005) messages.push(t('pos.fullPaymentRequired'));
            else if (settlementMode === 'partial' && paid >= Number(currentTotal)) messages.push(t('pos.partialMustBeLower'));
            if (messages.length > 0) {
                setPaymentIssues(messages);
                setError('');
                return;
            }
        }
        setPaymentIssues([]);
        setError('');

        const active = payments.filter((payment) => payment.method && positiveAmount(payment.amount) > 0);
        onReview({
            fulfillment_mode: checkoutState.fulfillment_mode,
            fulfillment_origin: checkoutState.fulfillment_origin,
            totals: {
                subtotal_excl_tax: sale.summary.subtotal_excl_tax,
                discount: discountTotal.toFixed(4),
                net_excl_tax: sale.summary.net_excl_tax,
                tax_total: sale.summary.tax_total,
                shipping: shippingNumeric.toFixed(4),
                total: currentTotal,
            },
            paid: paid.toFixed(4),
            remaining,
            payments: active.map((payment) => {
                const method = payment.method as PaymentMethod;
                const account = compatibleAccounts(method, financialAccounts).find((item) => item.id === payment.financial_account_id)
                    ?? compatibleAccounts(method, financialAccounts)[0]
                    ?? null;
                return {
                    method,
                    label: paymentLabels[method],
                    amount: payment.amount,
                    cash_received: method === 'cash' ? payment.cash_received || payment.amount : null,
                    reference: method !== 'cash' ? payment.reference || null : null,
                    financial_account_id: Number(account?.id ?? payment.financial_account_id),
                    account_label: account ? `${account.code} · ${account.name}` : null,
                };
            }),
            customer: selectedCustomer,
            delivery:
                checkoutState.fulfillment_mode === 'delivery'
                    ? { address: checkoutState.delivery_address, phone: checkoutState.delivery_phone, notes: checkoutState.delivery_notes }
                    : null,
            requires_replenishment: sale.requires_replenishment,
            remote_required: sale.remote_required,
            print: false,
        });
    }

    // A customer is optional (walk-in sales are valid server-side); it is only
    // advised when an unpaid balance or a delivery must be followed up.
    const customerAdvised = !selectedCustomer && (settlementMode !== 'full' || checkoutState.fulfillment_mode === 'delivery');

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {/* Header */}
            <div className="flex shrink-0 items-center gap-2 border-b border-line px-4 py-3">
                <Button variant="secondary" size="sm" onClick={onBack}>
                    <svg aria-hidden="true" className={direction === 'rtl' ? 'rotate-180' : undefined} width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 6 8 12l6 6" /></svg>
                    {t('pos.back')}
                </Button>
                <h2 className="text-base font-semibold text-ink">{t('pos.payment')}<SandboxPaymentBadge inline /></h2>
            </div>

            {/* Body (scroll) */}
            <div className="min-h-0 flex-1 overflow-y-auto">
                {/* Customer first: visible without scrolling, next to the totals */}
                <PosCustomerCard
                    customer={selectedCustomer}
                    attaching={attachingCustomer}
                    canUpdate={canUpdateCustomer}
                    recommended={customerAdvised}
                    onOpen={() => onOpenCustomer('search')}
                    onEdit={() => onOpenCustomer('edit')}
                    onRemove={onCustomerDetach}
                />

                {/* Totals */}
                <Section title={t('pos.summary')}>
                    <dl className="space-y-1 text-sm tabular-nums">
                        <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.grossExcl')}</dt><dd><Money value={sale.summary.subtotal_excl_tax} currency={currencyCode} /></dd></div>
                        {discountTotal > 0 && (
                            <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.discountExcl')}</dt><dd className="text-success">- <Money value={discountTotal.toFixed(4)} currency={currencyCode} /></dd></div>
                        )}
                        {discountTotal > 0 && (
                            <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.netExcl')}</dt><dd><Money value={sale.summary.net_excl_tax} currency={currencyCode} /></dd></div>
                        )}
                        <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.vat')}</dt><dd><Money value={sale.summary.tax_total} currency={currencyCode} /></dd></div>
                        {shippingNumeric > 0 && (
                            <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.shipping')}</dt><dd><Money value={shippingNumeric.toFixed(4)} currency={currencyCode} /></dd></div>
                        )}
                        <div className="flex items-baseline justify-between border-t border-line pt-1.5"><dt className="text-sm font-semibold text-ink">{t('pos.totalIncl')}</dt><dd className="metric-value text-lg font-semibold text-ink"><Money value={currentTotal} currency={currencyCode} /></dd></div>
                    </dl>
                </Section>

                {/* Pickup / Delivery */}
                <Section
                    title={t('pos.customerDiscount')}
                    action={(savingState || savingCheckout) ? <Spinner size="xs" className="text-ink-faint" /> : undefined}
                >
                    <div className="grid grid-cols-2 gap-2">
                        <ChoiceTile selected={checkoutState.fulfillment_mode === 'pickup'} title={t('pos.pickupNow')} help={t('pos.pickupHelp')} icon={icons.pickup} onClick={() => setFulfillment('pickup')} />
                        <ChoiceTile selected={checkoutState.fulfillment_mode === 'delivery'} title={t('pos.deliveryLater')} help={t('pos.prepareDeliver')} icon={icons.delivery} onClick={() => setFulfillment('delivery')} />
                    </div>

                    {checkoutState.fulfillment_mode === 'delivery' && (
                        <div className="mt-2.5 space-y-2">
                            <textarea
                                value={checkoutState.delivery_address}
                                onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_address: event.target.value }))}
                                onBlur={() => void saveCheckoutState()}
                                rows={2}
                                placeholder={t('pos.deliveryAddress')}
                                aria-label={t('pos.deliveryAddress')}
                                className="control-base px-3 py-2 text-sm"
                            />
                            <input
                                value={checkoutState.delivery_phone}
                                onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_phone: event.target.value }))}
                                onBlur={() => void saveCheckoutState()}
                                placeholder={t('pos.phone')}
                                aria-label={t('pos.phone')}
                                type="tel"
                                inputMode="tel"
                                className={fieldClass}
                            />
                            <label className="flex items-center gap-2 text-sm font-medium text-ink-muted">
                                {t('pos.shippingFee')}
                                <NumericInput
                                    value={checkoutState.shipping_fee === '0.0000' || checkoutState.shipping_fee === '0' ? '' : checkoutState.shipping_fee}
                                    onValueChange={(next) => setCheckoutState((current) => ({ ...current, shipping_fee: next || '0' }))}
                                    onCommit={() => void saveCheckoutState()}
                                    placeholder="0,00"
                                    className="control-input h-10 w-28 text-end text-sm tabular-nums"
                                />
                                <span className="text-ink-faint">DH</span>
                            </label>
                            <textarea
                                value={checkoutState.delivery_notes}
                                onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_notes: event.target.value }))}
                                onBlur={() => void saveCheckoutState()}
                                rows={2}
                                placeholder={t('pos.notes')}
                                aria-label={t('pos.notes')}
                                className="control-base px-3 py-2 text-sm"
                            />
                        </div>
                    )}
                </Section>

                {/* Fulfillment origin — where the goods physically leave from */}
                <Section title={t('pos.originTitle')}>
                    <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label={t('pos.originTitle')}>
                        {(['showroom', 'direct'] as const).map((origin) => {
                            const selected = checkoutState.fulfillment_origin === origin;
                            const unsupported = origin === 'direct' && checkoutState.fulfillment_mode === 'pickup';
                            return (
                                <ChoiceTile
                                    key={origin}
                                    role="radio"
                                    selected={selected}
                                    ariaDisabled={unsupported}
                                    tooltip={unsupported ? t('pos.originPickupDirectBlocked') : undefined}
                                    title={origin === 'showroom' ? t('pos.originShowroom') : t('pos.originDirect')}
                                    help={origin === 'showroom' ? t('pos.originShowroomHelp') : t('pos.originDirectHelp')}
                                    icon={origin === 'showroom' ? icons.showroom : icons.direct}
                                    onClick={() => setOrigin(origin)}
                                />
                            );
                        })}
                    </div>
                    <p className={`mt-2 rounded-field px-3 py-2 text-[13px] leading-5 ${checkoutState.fulfillment_origin === 'showroom' && sale.requires_replenishment ? 'bg-warning-soft text-warning' : 'bg-raised text-ink-muted'}`}>
                        {originExplanation(checkoutState.fulfillment_origin, sale.requires_replenishment, String(Number(sale.remote_required)), sale.warehouse?.name ?? t('pos.originShowroomFallback'), t)}
                    </p>
                </Section>

                {/* Payment */}
                <Section
                    title={t('pos.payment')}
                    action={
                        settlementMode !== 'deferred' ? (
                            <Button size="sm" variant="secondary" onClick={() => setPayments((current) => [...current, buildEmptyPayment()])}>
                                <PlusIcon />
                                {t('pos.addMethod')}
                            </Button>
                        ) : undefined
                    }
                >
                    <SegmentedControl
                        size="sm"
                        ariaLabel={t('pos.settlementMode')}
                        value={settlementMode}
                        onChange={setSettlement}
                        options={(['full', 'partial', 'deferred'] as SettlementMode[]).map((mode) => ({ value: mode, label: settlementLabels[mode] }))}
                    />

                    {settlementMode === 'deferred' ? (
                        <p className="mt-2.5 rounded-field border border-line bg-raised px-3 py-2 text-[13px] leading-5 text-ink-muted">
                            {t('pos.deferredDetailedHelp')}
                        </p>
                    ) : (
                    <div className="mt-2.5 space-y-2.5">
                        {payments.map((payment, index) => {
                            const compatible = compatibleAccounts(payment.method, financialAccounts);
                            return (
                                <div key={index} className="rounded-field border border-line p-2.5">
                                    <div className="flex items-center gap-2">
                                        <select
                                            value={payment.method}
                                            onChange={(event) => assignMethod(index, event.target.value as PaymentMethod | '')}
                                            aria-label={`${t('pos.method')} ${index + 1}`}
                                            aria-invalid={attempted && !payment.method ? true : undefined}
                                            className="control-select h-10 min-w-0 flex-1 text-sm"
                                        >
                                            <option value="">{t('pos.method')}</option>
                                            {(Object.keys(paymentLabels) as PaymentMethod[]).map((method) => (
                                                <option key={method} value={method}>{paymentLabels[method]}</option>
                                            ))}
                                        </select>
                                        <NumericInput
                                            value={payment.amount}
                                            onValueChange={(next) =>
                                                patchPayment(index, {
                                                    amount: next,
                                                    amountTouched: true,
                                                    cash_received: payment.method === 'cash' && !payment.cashReceivedTouched ? next : payment.cash_received,
                                                })
                                            }
                                            placeholder={t('pos.amount')}
                                            aria-label={`${t('pos.amount')} ${index + 1}`}
                                            aria-invalid={attempted && payment.method !== '' && positiveAmount(payment.amount) <= 0 ? true : undefined}
                                            className="control-input h-10 w-28 text-end text-sm tabular-nums"
                                        />
                                        {payments.length > 1 && (
                                            <IconButton
                                                label={t('pos.deletePayment')}
                                                size="sm"
                                                onClick={() => setPayments((current) => current.filter((_, itemIndex) => itemIndex !== index))}
                                                className="hover:bg-danger-soft hover:text-danger"
                                            >
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13" /></svg>
                                            </IconButton>
                                        )}
                                    </div>

                                    {payment.method === 'cash' && (
                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                            <label className="flex items-center gap-2 text-[13px] font-medium text-ink-muted">
                                                {t('pos.receipt')}
                                                <NumericInput
                                                    value={payment.cash_received}
                                                    onValueChange={(next) => patchPayment(index, { cash_received: next, cashReceivedTouched: true })}
                                                    aria-invalid={attempted && positiveAmount(payment.amount) > 0 && positiveAmount(payment.cash_received) < positiveAmount(payment.amount) ? true : undefined}
                                                    placeholder="0,00"
                                                    className="control-input h-10 w-28 text-end text-sm tabular-nums"
                                                />
                                            </label>
                                            <span className="text-[13px] text-ink-muted">
                                                {t('pos.change')} <strong className="text-ink"><Money value={Math.max(0, (Number(payment.cash_received) || 0) - positiveAmount(payment.amount)).toFixed(4)} currency={currencyCode} /></strong>
                                            </span>
                                        </div>
                                    )}

                                    {payment.method && payment.method !== 'cash' && (
                                        <input
                                            value={payment.reference}
                                            onChange={(event) => patchPayment(index, { reference: event.target.value })}
                                            placeholder={referenceLabels[payment.method]}
                                            aria-label={referenceLabels[payment.method]}
                                            className={`${fieldClass} mt-2`}
                                        />
                                    )}

                                    {payment.method && compatible.length > 1 && (
                                        <select
                                            value={payment.financial_account_id}
                                            onChange={(event) => patchPayment(index, { financial_account_id: event.target.value ? Number(event.target.value) : '' })}
                                            aria-label={t('pos.receiptAccount')}
                                            aria-invalid={attempted && payment.financial_account_id === '' ? true : undefined}
                                            className="control-select mt-2 h-10 text-sm"
                                        >
                                            <option value="">{t('pos.receiptAccount')}</option>
                                            {compatible.map((account) => (
                                                <option key={account.id} value={account.id}>{account.code} · {account.name}</option>
                                            ))}
                                        </select>
                                    )}

                                    {payment.method && compatible.length === 0 && (
                                        <p className="mt-2 rounded-field border border-warning/30 bg-warning-soft px-2.5 py-1.5 text-[13px] text-warning">
                                            {t('pos.noAccount')} <Link href="/financial-accounts" className="font-semibold underline">{t('pos.configure')}</Link>
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    )}

                    <div className="mt-2.5 space-y-1 rounded-field border border-line bg-raised px-3 py-2 text-sm tabular-nums">
                        <div className="flex justify-between"><span className="text-ink-muted">{t('pos.orderTotal')}</span><strong><Money value={currentTotal} currency={currencyCode} /></strong></div>
                        <div className="flex justify-between">
                            <span className="text-ink-muted">{settlementMode === 'deferred' ? t('pos.collectNow') : t('pos.receivedNow')}</span>
                            <strong><Money value={paid.toFixed(4)} currency={currencyCode} /></strong>
                        </div>
                        <div className="flex justify-between"><span className="text-ink-muted">{t('pos.remaining')}</span><strong className={Number(remaining) > 0 ? 'text-warning' : 'text-success'}><Money value={remaining} currency={currencyCode} /></strong></div>
                    </div>
                </Section>
            </div>

            {/* Footer */}
            <div className="shrink-0 border-t border-line bg-raised p-3.5">
                {/* Validation feedback lives next to the action that triggers it, never scrolled away. */}
                <div aria-live="assertive">
                    {error && (
                        <Alert tone="danger" className="mb-2.5 px-3 py-2">
                            {error}
                        </Alert>
                    )}
                    {paymentIssues.length === 1 && (
                        <Alert tone="danger" className="mb-2.5 px-3 py-2">
                            {paymentIssues[0]}
                        </Alert>
                    )}
                    {paymentIssues.length > 1 && (
                        <Alert tone="danger" className="mb-2.5 px-3 py-2" title={t('pos.fixPaymentIssues').replace('{count}', String(paymentIssues.length))}>
                            <ul className="mt-0.5 list-disc space-y-0.5 ps-4">
                                {paymentIssues.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </Alert>
                    )}
                </div>
                {sale.procurement_deficit && (
                    <Alert tone="warning" className="mb-2.5 px-3 py-2">
                        {t('pos.stockOutCheckoutHelp')}
                    </Alert>
                )}
                <div className="mb-2.5 flex items-baseline justify-between gap-3 tabular-nums">
                    <span className="text-sm font-semibold text-ink">{t('pos.totalIncl')}</span>
                    <span className="text-end">
                        <span className="metric-value block text-xl font-semibold text-ink"><Money value={currentTotal} currency={currencyCode} /></span>
                        {Number(remaining) > 0 && settlementMode !== 'full' && (
                            <span className="block text-[13px] font-medium text-warning">
                                {t('pos.remaining')} <Money value={remaining} currency={currencyCode} />
                            </span>
                        )}
                    </span>
                </div>
                <div className="grid grid-cols-[auto_1fr] gap-2">
                    <Button variant="secondary" size="lg" onClick={onBack}>
                        {t('pos.cancel')}
                    </Button>
                    <Button
                        size="lg"
                        disabled={savingCheckout || savingState || attachingCustomer || sale.procurement_deficit === true}
                        onClick={submitReview}
                    >
                        {(savingCheckout || savingState) && <Spinner size="sm" />}
                        {t('pos.reviewOrder')} <span aria-hidden="true">{direction === 'rtl' ? '←' : '→'}</span>
                    </Button>
                </div>
            </div>
        </div>
    );
}
