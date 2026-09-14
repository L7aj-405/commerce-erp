import { Money, NumericInput, Quantity, SegmentedControl } from '@/components/pos/primitives';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/toast';
import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { posJson } from './api';
import {
    type Account,
    type CheckoutState,
    type CustomerForm,
    type PaymentDraft,
    type PaymentMethod,
    type ReviewData,
    type SettlementMode,
    blankCustomerForm,
    buildEmptyPayment,
    buildInitialPayment,
    compatibleAccounts,
    customerFormFrom,
    customerTypeFor,
    firstCompatibleAccountId,
    normalizeAmount,
    paymentLabels,
    positiveAmount,
    referenceLabels,
    settlementLabels,
} from './pos-shared';
import type { ActiveSale, Customer } from './types';

type Props = {
    sale: ActiveSale;
    currencyCode: string;
    financialAccounts: Account[];
    canCreateCustomer: boolean;
    canUpdateCustomer: boolean;
    /** A fulfilment/shipping write to the draft is in flight. */
    savingCheckout: boolean;
    /** The selected customer is being attached to / detached from the draft. */
    attachingCustomer: boolean;
    onBack: () => void;
    onCustomerSelected: (customer: Customer) => Promise<void>;
    onCustomerUpdated: (customer: Customer) => Promise<void>;
    onCustomerDetach: () => void;
    onCheckoutStateSave: (state: CheckoutState) => Promise<void>;
    onReview: (data: ReviewData) => void;
};

function Section({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
    return (
        <section className="border-t border-line px-4 py-3.5 first:border-t-0">
            <div className="mb-2.5 flex items-center justify-between">
                <h3 className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{title}</h3>
                {action}
            </div>
            {children}
        </section>
    );
}

const fieldClass = 'h-9 w-full rounded-field border border-line-strong bg-surface px-2.5 text-[13px] outline-none transition-soft focus:border-primary';

export default function PosCheckoutPanel({
    sale,
    currencyCode,
    financialAccounts,
    canCreateCustomer,
    canUpdateCustomer,
    savingCheckout,
    attachingCustomer,
    onBack,
    onCustomerSelected,
    onCustomerUpdated,
    onCustomerDetach,
    onCheckoutStateSave,
    onReview,
}: Props) {
    const toast = useToast();
    const [checkoutState, setCheckoutState] = useState<CheckoutState>({
        fulfillment_mode: sale.checkout.fulfillment_mode,
        shipping_fee: sale.checkout.shipping_fee,
        delivery_address: sale.checkout.delivery_address ?? '',
        delivery_phone: sale.checkout.delivery_phone ?? '',
        delivery_notes: sale.checkout.delivery_notes ?? '',
    });
    const [settlementMode, setSettlementMode] = useState<SettlementMode>('full');
    const [payments, setPayments] = useState<PaymentDraft[]>(() => [buildInitialPayment(sale.summary.total, financialAccounts)]);
    const [error, setError] = useState('');
    const [savingState, setSavingState] = useState(false);

    // Customer search
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<Customer[]>([]);
    const [searching, setSearching] = useState(false);
    const [searched, setSearched] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const debouncedSearch = useDebouncedValue(search.trim(), 250);
    const [customerType, setCustomerType] = useState<'individual' | 'business'>(customerTypeFor(sale.customer));
    const [customerForm, setCustomerForm] = useState<CustomerForm>(customerFormFrom(sale.customer));
    const [customerMode, setCustomerMode] = useState<'idle' | 'create' | 'edit'>('idle');
    const [customerBusy, setCustomerBusy] = useState(false);

    const selectedCustomer = sale.customer;

    useEffect(() => {
        if (debouncedSearch.length < 2) {
            abortRef.current?.abort();
            setResults([]);
            setSearching(false);
            setSearched(false);
            return;
        }
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        setSearching(true);
        fetch(`/pos/customers?${new URLSearchParams({ search: debouncedSearch })}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) throw new Error('search failed');
                return response.json() as Promise<{ data: Customer[] }>;
            })
            .then((payload) => {
                setResults(payload.data);
                setSearched(true);
            })
            .catch((reason: Error) => {
                if (reason.name !== 'AbortError') setError('La recherche client a échoué.');
            })
            .finally(() => setSearching(false));
        return () => controller.abort();
    }, [debouncedSearch]);

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
            if (!payment.cash_received) return `Paiement ${index + 1} (espèces) : renseignez le montant reçu.`;
            if (positiveAmount(payment.cash_received) < positiveAmount(payment.amount)) return `Paiement ${index + 1} (espèces) : le montant reçu doit être ≥ au montant payé.`;
            return null;
        })
        .filter((message): message is string => message !== null);
    const accountErrors = payments
        .map((payment, index) => {
            if (!payment.method) return null;
            const compatible = compatibleAccounts(payment.method, financialAccounts);
            if (compatible.length === 0) return `Paiement ${index + 1} : aucun compte de réception configuré pour ${paymentLabels[payment.method]}.`;
            if (compatible.length > 1 && payment.financial_account_id === '') return `Paiement ${index + 1} : sélectionnez un compte de réception.`;
            return null;
        })
        .filter((message): message is string => message !== null);

    async function saveCheckoutState(next = checkoutState) {
        setSavingState(true);
        setError('');
        try {
            await onCheckoutStateSave({
                fulfillment_mode: next.fulfillment_mode,
                shipping_fee: next.fulfillment_mode === 'delivery' ? next.shipping_fee || '0' : '0',
                delivery_address: next.fulfillment_mode === 'delivery' ? next.delivery_address : '',
                delivery_phone: next.fulfillment_mode === 'delivery' ? next.delivery_phone : '',
                delivery_notes: next.fulfillment_mode === 'delivery' ? next.delivery_notes : '',
            });
        } catch {
            setError('La mise à jour du mode de remise a échoué.');
        } finally {
            setSavingState(false);
        }
    }

    function setFulfillment(mode: 'pickup' | 'delivery') {
        const next: CheckoutState = mode === 'pickup'
            ? { fulfillment_mode: 'pickup', shipping_fee: '0', delivery_address: '', delivery_phone: '', delivery_notes: '' }
            : { ...checkoutState, fulfillment_mode: 'delivery' };
        setCheckoutState(next);
        void saveCheckoutState(next);
    }

    function patchPayment(index: number, patch: Partial<PaymentDraft>) {
        setPayments((current) => current.map((payment, itemIndex) => (itemIndex === index ? { ...payment, ...patch } : payment)));
    }

    function setSettlement(mode: SettlementMode) {
        setSettlementMode(mode);
        setError('');
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

    async function createCustomer() {
        setCustomerBusy(true);
        setError('');
        try {
            const payload = await posJson<{ data: Customer }>('/pos/customers', { method: 'POST', body: { type: customerType, ...customerForm } });
            await onCustomerSelected(payload.data);
            setCustomerMode('idle');
            setSearch('');
            toast.success('Client enregistré');
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'La création du client a échoué.');
        } finally {
            setCustomerBusy(false);
        }
    }

    async function updateCustomer() {
        if (!selectedCustomer) return;
        setCustomerBusy(true);
        setError('');
        try {
            const payload = await posJson<{ data: Customer }>(`/pos/customers/${selectedCustomer.id}`, { method: 'PATCH', body: { type: customerType, ...customerForm } });
            await onCustomerUpdated(payload.data);
            setCustomerMode('idle');
            toast.success('Client mis à jour');
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'La mise à jour du client a échoué.');
        } finally {
            setCustomerBusy(false);
        }
    }

    function submitReview() {
        if (settlementMode !== 'deferred') {
            const messages = [...cashErrors, ...accountErrors];
            if (messages.length > 0) {
                setError(messages[0]);
                return;
            }
            if (paid <= 0) {
                setError('Sélectionnez un moyen de paiement et un montant.');
                return;
            }
            if (settlementMode === 'full' && Math.abs(Number(currentTotal) - paid) > 0.00005) {
                setError('Le paiement comptant doit couvrir la totalité du montant de la commande.');
                return;
            }
            if (settlementMode === 'partial' && paid >= Number(currentTotal)) {
                setError('Un paiement partiel doit rester inférieur au total de la commande.');
                return;
            }
        }
        setError('');

        const active = payments.filter((payment) => payment.method && positiveAmount(payment.amount) > 0);
        onReview({
            fulfillment_mode: checkoutState.fulfillment_mode,
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

    function renderCustomerForm(action: 'create' | 'edit') {
        const isCreate = action === 'create';
        const set = <K extends keyof CustomerForm>(field: K, value: CustomerForm[K]) => setCustomerForm((current) => ({ ...current, [field]: value }));
        return (
            <div className="mt-2 space-y-2">
                <SegmentedControl
                    size="sm"
                    ariaLabel="Type de client"
                    value={customerType}
                    onChange={setCustomerType}
                    options={[
                        { value: 'individual', label: 'Particulier' },
                        { value: 'business', label: 'Entreprise' },
                    ]}
                />
                {customerType === 'individual' ? (
                    <input value={customerForm.display_name} onChange={(event) => set('display_name', event.target.value)} placeholder="Nom complet *" className={fieldClass} />
                ) : (
                    <>
                        <input value={customerForm.company_name} onChange={(event) => set('company_name', event.target.value)} placeholder="Raison sociale *" className={fieldClass} />
                        <input value={customerForm.contact_name} onChange={(event) => set('contact_name', event.target.value)} placeholder="Contact" className={fieldClass} />
                    </>
                )}
                <input value={customerForm.phone} onChange={(event) => set('phone', event.target.value)} placeholder="Téléphone" className={fieldClass} />
                <input value={customerForm.email} onChange={(event) => set('email', event.target.value)} placeholder="Email" className={fieldClass} />
                {customerType === 'business' && (
                    <input value={customerForm.tax_identifier} onChange={(event) => set('tax_identifier', event.target.value)} placeholder="ICE / IF / RC" className={fieldClass} />
                )}
                <div className="flex gap-2">
                    <Button
                        type="button"
                        size="sm"
                        loading={customerBusy}
                        loadingText="Enregistrement…"
                        onClick={() => void (isCreate ? createCustomer() : updateCustomer())}
                        className="px-3 py-2 text-[13px]"
                    >
                        {isCreate ? 'Créer le client' : 'Enregistrer'}
                    </Button>
                    <button type="button" disabled={customerBusy} onClick={() => setCustomerMode('idle')} className="rounded-field border border-line-strong px-3 py-2 text-[13px] font-medium text-ink-muted disabled:opacity-50">
                        Annuler
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {/* Header */}
            <div className="flex shrink-0 items-center gap-2 border-b border-line px-4 py-3">
                <button type="button" onClick={onBack} className="flex items-center gap-1 rounded-field px-2 py-1 text-[13px] font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 6 8 12l6 6" /></svg>
                    Retour
                </button>
                <h2 className="text-sm font-semibold text-ink">Paiement</h2>
            </div>

            {/* Body (scroll) */}
            <div className="min-h-0 flex-1 overflow-y-auto">
                {error && <p className="mx-4 mt-3 rounded-field bg-danger-soft px-3 py-2 text-[12px] text-danger">{error}</p>}

                {/* Totals */}
                <Section title="Récapitulatif">
                    <dl className="space-y-1 text-[13px]">
                        <div className="flex justify-between"><dt className="text-ink-muted">Sous-total HT</dt><dd><Money value={sale.summary.subtotal_excl_tax} currency={currencyCode} /></dd></div>
                        {discountTotal > 0 && (
                            <div className="flex justify-between"><dt className="text-ink-muted">Remise</dt><dd className="text-success">- <Money value={discountTotal.toFixed(4)} currency={currencyCode} /></dd></div>
                        )}
                        {discountTotal > 0 && (
                            <div className="flex justify-between"><dt className="text-ink-muted">Net HT</dt><dd><Money value={sale.summary.net_excl_tax} currency={currencyCode} /></dd></div>
                        )}
                        <div className="flex justify-between"><dt className="text-ink-muted">TVA</dt><dd><Money value={sale.summary.tax_total} currency={currencyCode} /></dd></div>
                        {shippingNumeric > 0 && (
                            <div className="flex justify-between"><dt className="text-ink-muted">Livraison</dt><dd><Money value={shippingNumeric.toFixed(4)} currency={currencyCode} /></dd></div>
                        )}
                        <div className="flex items-baseline justify-between border-t border-line pt-1.5"><dt className="text-sm font-semibold text-ink">Total TTC</dt><dd className="text-base font-semibold text-ink"><Money value={currentTotal} currency={currencyCode} /></dd></div>
                    </dl>
                </Section>

                {/* Pickup / Delivery */}
                <Section
                    title="Remise au client"
                    action={(savingState || savingCheckout) ? <Spinner size="xs" className="text-ink-faint" /> : undefined}
                >
                    <div className="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            onClick={() => setFulfillment('pickup')}
                            className={`rounded-field border p-2.5 text-left transition-soft ${checkoutState.fulfillment_mode === 'pickup' ? 'border-primary bg-sage' : 'border-line-strong hover:bg-raised'}`}
                        >
                            <span className="text-[13px] font-semibold text-ink">⚡ Retrait immédiat</span>
                            <span className="mt-0.5 block text-[11px] text-ink-muted">Le client prend maintenant</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setFulfillment('delivery')}
                            className={`rounded-field border p-2.5 text-left transition-soft ${checkoutState.fulfillment_mode === 'delivery' ? 'border-primary bg-sage' : 'border-line-strong hover:bg-raised'}`}
                        >
                            <span className="text-[13px] font-semibold text-ink">🚚 Livraison / plus tard</span>
                            <span className="mt-0.5 block text-[11px] text-ink-muted">Préparer / livrer</span>
                        </button>
                    </div>

                    {checkoutState.fulfillment_mode === 'pickup' && sale.requires_replenishment && (
                        <p className="mt-2 rounded-field bg-warning-soft px-3 py-2 text-[12px] text-warning">
                            <Quantity value={sale.remote_required} /> unité(s) nécessitent une préparation depuis un autre emplacement.
                        </p>
                    )}

                    {checkoutState.fulfillment_mode === 'delivery' && (
                        <div className="mt-2.5 space-y-2">
                            <textarea
                                value={checkoutState.delivery_address}
                                onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_address: event.target.value }))}
                                onBlur={() => void saveCheckoutState()}
                                rows={2}
                                placeholder="Adresse de livraison"
                                className="w-full rounded-field border border-line-strong bg-surface px-2.5 py-2 text-[13px] outline-none focus:border-primary"
                            />
                            <input
                                value={checkoutState.delivery_phone}
                                onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_phone: event.target.value }))}
                                onBlur={() => void saveCheckoutState()}
                                placeholder="Téléphone"
                                className={fieldClass}
                            />
                            <label className="flex items-center gap-2 text-[13px] text-ink-muted">
                                Frais de livraison
                                <NumericInput
                                    value={checkoutState.shipping_fee === '0.0000' || checkoutState.shipping_fee === '0' ? '' : checkoutState.shipping_fee}
                                    onValueChange={(next) => setCheckoutState((current) => ({ ...current, shipping_fee: next || '0' }))}
                                    onCommit={() => void saveCheckoutState()}
                                    placeholder="0,00"
                                    className="h-9 w-24 rounded-field border border-line-strong bg-surface px-2.5 text-[13px] outline-none focus:border-primary"
                                />
                                <span className="text-ink-faint">DH</span>
                            </label>
                            <textarea
                                value={checkoutState.delivery_notes}
                                onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_notes: event.target.value }))}
                                onBlur={() => void saveCheckoutState()}
                                rows={2}
                                placeholder="Notes"
                                className="w-full rounded-field border border-line-strong bg-surface px-2.5 py-2 text-[13px] outline-none focus:border-primary"
                            />
                        </div>
                    )}
                </Section>

                {/* Payment */}
                <Section
                    title="Paiement"
                    action={
                        settlementMode !== 'deferred' ? (
                            <button type="button" onClick={() => setPayments((current) => [...current, buildEmptyPayment()])} className="text-[12px] font-medium text-ink-muted transition-soft hover:text-ink">
                                + Ajouter un moyen
                            </button>
                        ) : undefined
                    }
                >
                    <SegmentedControl
                        size="sm"
                        ariaLabel="Mode de règlement"
                        value={settlementMode}
                        onChange={setSettlement}
                        options={(['full', 'partial', 'deferred'] as SettlementMode[]).map((mode) => ({ value: mode, label: settlementLabels[mode] }))}
                    />

                    {settlementMode === 'deferred' ? (
                        <p className="mt-2.5 rounded-field bg-raised px-3 py-2 text-[12px] text-ink-muted">
                            Le client règlera plus tard, après réception de la facture. Aucun mouvement de caisse ne sera enregistré maintenant.
                        </p>
                    ) : (
                    <div className="mt-2.5 space-y-2.5">
                        {payments.map((payment, index) => {
                            const compatible = compatibleAccounts(payment.method, financialAccounts);
                            return (
                                <div key={index} className="rounded-field border border-line p-2.5">
                                    <div className="flex items-center gap-2">
                                        <select value={payment.method} onChange={(event) => assignMethod(index, event.target.value as PaymentMethod | '')} className={`${fieldClass} flex-1`}>
                                            <option value="">Méthode…</option>
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
                                            placeholder="Montant"
                                            className={`${fieldClass} w-24`}
                                        />
                                        {payments.length > 1 && (
                                            <button type="button" onClick={() => setPayments((current) => current.filter((_, itemIndex) => itemIndex !== index))} aria-label="Supprimer" className="shrink-0 text-ink-faint transition-soft hover:text-danger">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                            </button>
                                        )}
                                    </div>

                                    {payment.method === 'cash' && (
                                        <div className="mt-2 flex items-center gap-2">
                                            <span className="text-[12px] text-ink-muted">Reçu</span>
                                            <NumericInput
                                                value={payment.cash_received}
                                                onValueChange={(next) => patchPayment(index, { cash_received: next, cashReceivedTouched: true })}
                                                placeholder="0,00"
                                                className={`${fieldClass} w-24`}
                                            />
                                            <span className="text-[12px] text-ink-muted">
                                                Monnaie <strong className="text-ink"><Money value={Math.max(0, (Number(payment.cash_received) || 0) - positiveAmount(payment.amount)).toFixed(4)} currency={currencyCode} /></strong>
                                            </span>
                                        </div>
                                    )}

                                    {payment.method && payment.method !== 'cash' && (
                                        <input
                                            value={payment.reference}
                                            onChange={(event) => patchPayment(index, { reference: event.target.value })}
                                            placeholder={referenceLabels[payment.method]}
                                            className={`${fieldClass} mt-2`}
                                        />
                                    )}

                                    {payment.method && compatible.length > 1 && (
                                        <select
                                            value={payment.financial_account_id}
                                            onChange={(event) => patchPayment(index, { financial_account_id: event.target.value ? Number(event.target.value) : '' })}
                                            className={`${fieldClass} mt-2`}
                                        >
                                            <option value="">Compte de réception…</option>
                                            {compatible.map((account) => (
                                                <option key={account.id} value={account.id}>{account.code} · {account.name}</option>
                                            ))}
                                        </select>
                                    )}

                                    {payment.method && compatible.length === 0 && (
                                        <p className="mt-2 rounded-field bg-warning-soft px-2.5 py-1.5 text-[12px] text-warning">
                                            Aucun compte configuré. <Link href="/financial-accounts" className="underline">Configurer</Link>
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    )}

                    <div className="mt-2.5 space-y-1 rounded-field bg-raised px-3 py-2 text-[13px]">
                        <div className="flex justify-between"><span className="text-ink-muted">Total commande</span><strong><Money value={currentTotal} currency={currencyCode} /></strong></div>
                        <div className="flex justify-between">
                            <span className="text-ink-muted">{settlementMode === 'deferred' ? 'À encaisser maintenant' : 'Montant encaissé maintenant'}</span>
                            <strong><Money value={paid.toFixed(4)} currency={currencyCode} /></strong>
                        </div>
                        <div className="flex justify-between"><span className="text-ink-muted">Reste à payer</span><strong className={Number(remaining) > 0 ? 'text-warning' : 'text-ink'}><Money value={remaining} currency={currencyCode} /></strong></div>
                    </div>
                </Section>

                {/* Customer */}
                <Section title="Client">
                    {selectedCustomer ? (
                        <div className="rounded-field border border-line bg-raised p-2.5">
                            <p className="text-[13px] font-semibold text-ink">{selectedCustomer.display_name}</p>
                            <p className="text-[12px] text-ink-muted">
                                {[selectedCustomer.company_name, selectedCustomer.phone, selectedCustomer.email].filter(Boolean).join(' · ') || 'Client comptoir'}
                            </p>
                            <div className="mt-2 flex gap-2">
                                <button type="button" onClick={() => { setSearch(''); setResults([]); setCustomerMode('idle'); onCustomerDetach(); }} className="text-[12px] font-medium text-ink-muted underline">
                                    Changer
                                </button>
                                {canUpdateCustomer && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setCustomerType(customerTypeFor(selectedCustomer));
                                            setCustomerForm(customerFormFrom(selectedCustomer));
                                            setCustomerMode(customerMode === 'edit' ? 'idle' : 'edit');
                                        }}
                                        className="text-[12px] font-medium text-ink-muted underline"
                                    >
                                        Modifier
                                    </button>
                                )}
                            </div>
                            {customerMode === 'edit' && canUpdateCustomer && renderCustomerForm('edit')}
                        </div>
                    ) : (
                        <>
                            <div className="relative">
                                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Rechercher un client…" className={fieldClass} aria-busy={searching || undefined} />
                                {searching && (
                                    <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink-faint">
                                        <Spinner size="xs" />
                                    </span>
                                )}
                            </div>
                            {results.length > 0 && (
                                <ul className="mt-2 max-h-44 space-y-1 overflow-y-auto">
                                    {results.map((customer) => (
                                        <li key={customer.id}>
                                            <button
                                                type="button"
                                                onClick={() => void onCustomerSelected(customer).then(() => { setSearch(''); setResults([]); })}
                                                className="w-full rounded-field border border-line px-2.5 py-1.5 text-left transition-soft hover:bg-raised"
                                            >
                                                <span className="block text-[13px] font-medium text-ink">{customer.display_name}</span>
                                                <span className="block text-[11px] text-ink-muted">{[customer.company_name, customer.phone, customer.email].filter(Boolean).join(' · ') || '—'}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {searched && !searching && results.length === 0 && <p className="mt-2 text-[12px] text-ink-muted">Aucun client trouvé.</p>}
                            {canCreateCustomer && customerMode !== 'create' && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setCustomerType('individual');
                                        setCustomerForm({ ...blankCustomerForm(), display_name: search.trim() });
                                        setCustomerMode('create');
                                    }}
                                    className="mt-2 text-[12px] font-medium text-ink-muted underline"
                                >
                                    + Nouveau client
                                </button>
                            )}
                            {customerMode === 'create' && renderCustomerForm('create')}
                        </>
                    )}
                </Section>
            </div>

            {/* Footer */}
            <div className="shrink-0 border-t border-line bg-raised p-3.5">
                {sale.procurement_deficit && (
                    <p className="mb-2 rounded-field border border-warning/30 bg-warning-soft/40 px-3 py-2 text-[12px] text-warning">
                        Des articles sont en rupture. Revenez au panier et utilisez « Approvisionner auprès d’un fournisseur »,
                        faites confirmer la disponibilité, puis finalisez.
                    </p>
                )}
                <div className="grid grid-cols-[auto_1fr] gap-2">
                    <button type="button" onClick={onBack} className="rounded-field border border-line-strong bg-surface px-3 py-2.5 text-[13px] font-medium text-ink transition-soft hover:bg-sage">
                        Annuler
                    </button>
                    <button
                        type="button"
                        disabled={savingCheckout || savingState || attachingCustomer || sale.procurement_deficit === true}
                        onClick={submitReview}
                        className="inline-flex items-center justify-center gap-2 rounded-field bg-primary px-4 py-2.5 text-sm font-semibold text-primary-fg transition-soft hover:bg-primary-hover disabled:opacity-50"
                    >
                        {(savingCheckout || savingState) && <Spinner size="sm" />}
                        Vérifier la commande →
                    </button>
                </div>
            </div>
        </div>
    );
}
