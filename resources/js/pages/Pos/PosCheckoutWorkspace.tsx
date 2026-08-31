import { Link } from '@inertiajs/react';
import { formatMoney } from '@/utils/format';
import { posJson } from './api';
import type { ActiveSale, Customer } from './types';
import { useEffect, useMemo, useState } from 'react';

type Account = { id: number; name: string; code: string; type: string; currency_code: string };
type PaymentMethod = 'cash' | 'card' | 'bank_transfer' | 'cheque';
type PaymentDraft = {
    method: PaymentMethod | '';
    financial_account_id: number | '';
    amount: string;
    cash_received: string;
    reference: string;
    amountTouched: boolean;
    cashReceivedTouched: boolean;
};
type CheckoutState = {
    fulfillment_mode: 'pickup' | 'delivery';
    shipping_fee: string;
    delivery_address: string;
    delivery_phone: string;
    delivery_notes: string;
};
type CustomerForm = {
    display_name: string;
    company_name: string;
    contact_name: string;
    phone: string;
    email: string;
    tax_identifier: string;
    billing_address: string;
};

type Props = {
    open: boolean;
    sale: ActiveSale | null;
    currencyCode: string;
    financialAccounts: Account[];
    canCreateCustomer: boolean;
    canUpdateCustomer: boolean;
    onClose: () => void;
    onCustomerSelected: (customer: Customer) => Promise<void>;
    onCustomerUpdated: (customer: Customer) => Promise<void>;
    onCheckoutStateSave: (state: CheckoutState) => Promise<void>;
    onComplete: (payload: {
        fulfillment_mode: 'pickup' | 'delivery';
        payments: Array<{ method: string; financial_account_id: number; amount: string; cash_received?: string | null; reference?: string | null }>;
    }) => Promise<void>;
};

const paymentLabels: Record<PaymentMethod, string> = {
    cash: 'Espèces',
    card: 'TPE / Carte',
    bank_transfer: 'Virement',
    cheque: 'Chèque',
};

const accountTypes: Record<PaymentMethod, string[]> = {
    cash: ['cash'],
    card: ['card_clearing'],
    bank_transfer: ['bank'],
    cheque: ['cheque_clearing'],
};

const blankCustomerForm = (): CustomerForm => ({
    display_name: '',
    company_name: '',
    contact_name: '',
    phone: '',
    email: '',
    tax_identifier: '',
    billing_address: '',
});

function normalizeAmount(value: string): string {
    const numeric = Number(value);

    if (!Number.isFinite(numeric) || numeric <= 0) {
        return '';
    }

    return numeric.toFixed(4);
}

function positiveAmount(value: string): number {
    const numeric = Number(value);

    return Number.isFinite(numeric) && numeric > 0 ? numeric : 0;
}

function customerTypeFor(customer: Customer | null): 'individual' | 'business' {
    return customer?.type === 'business' ? 'business' : 'individual';
}

function customerFormFrom(customer: Customer | null): CustomerForm {
    if (!customer) {
        return blankCustomerForm();
    }

    return {
        display_name: customer.type === 'business' ? '' : customer.display_name,
        company_name: customer.company_name ?? '',
        contact_name: customer.type === 'business' ? customer.display_name : '',
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        tax_identifier: customer.tax_identifier ?? '',
        billing_address: customer.billing_address ?? '',
    };
}

function compatibleAccounts(method: PaymentMethod | '', financialAccounts: Account[]): Account[] {
    if (!method) {
        return [];
    }

    return financialAccounts.filter((account) => accountTypes[method].includes(account.type));
}

function firstCompatibleAccountId(method: PaymentMethod | '', financialAccounts: Account[]): number | '' {
    return compatibleAccounts(method, financialAccounts)[0]?.id ?? '';
}

function buildInitialPayment(total: string, financialAccounts: Account[]): PaymentDraft {
    return {
        method: 'cash',
        financial_account_id: firstCompatibleAccountId('cash', financialAccounts),
        amount: normalizeAmount(total),
        cash_received: normalizeAmount(total),
        reference: '',
        amountTouched: false,
        cashReceivedTouched: false,
    };
}

function buildEmptyPayment(): PaymentDraft {
    return {
        method: '',
        financial_account_id: '',
        amount: '',
        cash_received: '',
        reference: '',
        amountTouched: false,
        cashReceivedTouched: false,
    };
}

export default function PosCheckoutWorkspace({
    open,
    sale,
    currencyCode,
    financialAccounts,
    canCreateCustomer,
    canUpdateCustomer,
    onClose,
    onCustomerSelected,
    onCustomerUpdated,
    onCheckoutStateSave,
    onComplete,
}: Props) {
    const [step, setStep] = useState(1);
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<Customer[]>([]);
    const [showCustomerPicker, setShowCustomerPicker] = useState(true);
    const [showCreate, setShowCreate] = useState(false);
    const [showEdit, setShowEdit] = useState(false);
    const [customerType, setCustomerType] = useState<'individual' | 'business'>('individual');
    const [customerForm, setCustomerForm] = useState<CustomerForm>(blankCustomerForm());
    const [payments, setPayments] = useState<PaymentDraft[]>([]);
    const [checkoutState, setCheckoutState] = useState<CheckoutState>({
        fulfillment_mode: 'pickup',
        shipping_fee: '0',
        delivery_address: '',
        delivery_phone: '',
        delivery_notes: '',
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!open || !sale) {
            return;
        }

        setStep(1);
        setSearch('');
        setResults([]);
        setError('');
        setShowCustomerPicker(!sale.customer);
        setShowCreate(false);
        setShowEdit(false);
        setCustomerType(customerTypeFor(sale.customer));
        setCustomerForm(customerFormFrom(sale.customer));
        setPayments([buildInitialPayment(sale.summary.total, financialAccounts)]);
        setCheckoutState({
            fulfillment_mode: sale.checkout.fulfillment_mode,
            shipping_fee: sale.checkout.shipping_fee,
            delivery_address: sale.checkout.delivery_address ?? '',
            delivery_phone: sale.checkout.delivery_phone ?? '',
            delivery_notes: sale.checkout.delivery_notes ?? '',
        });
    }, [open, sale?.id, financialAccounts]);

    const saleBaseTotal = sale ? Number(sale.summary.merchandise_total) - Number(sale.summary.global_discount_amount) : 0;
    const shippingNumeric = checkoutState.fulfillment_mode === 'delivery' ? Math.max(0, Number(checkoutState.shipping_fee) || 0) : 0;
    const currentTotal = useMemo(() => Math.max(0, saleBaseTotal + shippingNumeric).toFixed(4), [saleBaseTotal, shippingNumeric]);
    const paid = useMemo(() => payments.reduce((carry, payment) => carry + positiveAmount(payment.amount), 0), [payments]);
    const remaining = useMemo(() => Math.max(0, Number(currentTotal) - paid).toFixed(4), [currentTotal, paid]);
    const selectedCustomer = sale?.customer ?? null;
    const paymentBreakdown = payments.filter((payment) => payment.method && positiveAmount(payment.amount) > 0);
    const cashValidationErrors = payments
        .map((payment, index) => {
            if (payment.method !== 'cash' || positiveAmount(payment.amount) <= 0) {
                return null;
            }

            if (!payment.cash_received) {
                return `Paiement ${index + 1} en espèces : renseignez le montant reçu du client.`;
            }

            if (positiveAmount(payment.cash_received) < positiveAmount(payment.amount)) {
                return `Paiement ${index + 1} en espèces : le montant reçu du client doit être supérieur ou égal au montant payé.`;
            }

            return null;
        })
        .filter((message): message is string => message !== null);
    const paymentConfigurationErrors = payments
        .map((payment, index) => {
            if (!payment.method) {
                return null;
            }

            const compatible = compatibleAccounts(payment.method, financialAccounts);
            if (compatible.length === 0) {
                return `Paiement ${index + 1} : aucun compte de réception compatible n’est configuré pour ${paymentLabels[payment.method]}.`;
            }

            if (compatible.length > 1 && payment.financial_account_id === '') {
                return `Paiement ${index + 1} : sélectionnez un compte de réception.`;
            }

            return null;
        })
        .filter((message): message is string => message !== null);

    if (!open || !sale) {
        return null;
    }

    function setCustomerFormField<K extends keyof CustomerForm>(field: K, value: CustomerForm[K]) {
        setCustomerForm((current) => ({ ...current, [field]: value }));
    }

    async function lookupCustomer() {
        if (!search.trim()) {
            return;
        }

        setError('');
        const response = await fetch(`/pos/customers?${new URLSearchParams({ search: search.trim() })}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
            setError('La recherche client a échoué.');
            return;
        }

        const payload = await response.json() as { data: Customer[] };
        setResults(payload.data);
    }

    async function createCustomer() {
        setBusy(true);
        setError('');

        try {
            const payload = await posJson<{ data: Customer }>('/pos/customers', {
                method: 'POST',
                body: {
                    type: customerType,
                    ...customerForm,
                },
            });
            await onCustomerSelected(payload.data);
            setCustomerType(customerTypeFor(payload.data));
            setCustomerForm(customerFormFrom(payload.data));
            setShowCreate(false);
            setShowCustomerPicker(false);
            setStep(2);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'La création du client a échoué.');
        } finally {
            setBusy(false);
        }
    }

    async function updateCustomer() {
        if (!selectedCustomer) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            const payload = await posJson<{ data: Customer }>(`/pos/customers/${selectedCustomer.id}`, {
                method: 'PATCH',
                body: {
                    type: customerType,
                    ...customerForm,
                },
            });

            await onCustomerUpdated(payload.data);
            setCustomerType(customerTypeFor(payload.data));
            setCustomerForm(customerFormFrom(payload.data));
            setShowEdit(false);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'La mise à jour du client a échoué.');
        } finally {
            setBusy(false);
        }
    }

    async function saveCheckoutState(nextState = checkoutState) {
        setBusy(true);
        setError('');

        try {
            await onCheckoutStateSave({
                fulfillment_mode: nextState.fulfillment_mode,
                shipping_fee: nextState.fulfillment_mode === 'delivery' ? (nextState.shipping_fee || '0') : '0',
                delivery_address: nextState.fulfillment_mode === 'delivery' ? nextState.delivery_address : '',
                delivery_phone: nextState.fulfillment_mode === 'delivery' ? nextState.delivery_phone : '',
                delivery_notes: nextState.fulfillment_mode === 'delivery' ? nextState.delivery_notes : '',
            });
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'La mise à jour du checkout a échoué.');
            throw reason;
        } finally {
            setBusy(false);
        }
    }

    async function setFulfillmentMode(mode: 'pickup' | 'delivery') {
        const nextState: CheckoutState = mode === 'pickup'
            ? {
                fulfillment_mode: 'pickup',
                shipping_fee: '0',
                delivery_address: '',
                delivery_phone: '',
                delivery_notes: '',
            }
            : {
                ...checkoutState,
                fulfillment_mode: 'delivery',
            };

        setCheckoutState(nextState);
        await saveCheckoutState(nextState);
    }

    function assignSuggestedAmount(index: number, method: PaymentMethod | '') {
        setPayments((current) => current.map((payment, itemIndex) => {
            if (itemIndex !== index) {
                return payment;
            }

            const compatible = compatibleAccounts(method, financialAccounts);
            const currentAccountStillCompatible = compatible.some((account) => account.id === payment.financial_account_id);
            const allocated = current.reduce((carry, row, rowIndex) => rowIndex === index ? carry : carry + positiveAmount(row.amount), 0);
            const suggestion = Math.max(0, Number(currentTotal) - allocated).toFixed(4);
            const amount = payment.amountTouched ? payment.amount : (method ? normalizeAmount(suggestion) : '');

            return {
                ...payment,
                method,
                financial_account_id: method
                    ? (currentAccountStillCompatible ? payment.financial_account_id : firstCompatibleAccountId(method, financialAccounts))
                    : '',
                amount,
                cash_received: method === 'cash'
                    ? (payment.cashReceivedTouched ? payment.cash_received : amount)
                    : '',
                cashReceivedTouched: method === 'cash' ? payment.cashReceivedTouched : false,
            };
        }));
    }

    function validatePaymentStep(): boolean {
        const messages = [...cashValidationErrors, ...paymentConfigurationErrors];

        if (messages.length > 0) {
            setError(messages[0]);

            return false;
        }

        return true;
    }

    function renderCustomerForm(action: 'create' | 'edit') {
        const isCreate = action === 'create';

        return (
            <div className="mt-4 grid gap-3">
                <div className="flex gap-4 text-sm">
                    <label className="inline-flex items-center gap-2">
                        <input type="radio" checked={customerType === 'individual'} onChange={() => setCustomerType('individual')} />
                        Particulier
                    </label>
                    <label className="inline-flex items-center gap-2">
                        <input type="radio" checked={customerType === 'business'} onChange={() => setCustomerType('business')} />
                        Entreprise
                    </label>
                </div>

                {customerType === 'individual' ? (
                    <label className="grid gap-1 text-sm text-slate-700">
                        <span>Nom complet</span>
                        <input value={customerForm.display_name} onChange={(event) => setCustomerFormField('display_name', event.target.value)} placeholder="Nom complet *" className="rounded-2xl border border-slate-300 px-4 py-3" />
                    </label>
                ) : (
                    <>
                        <label className="grid gap-1 text-sm text-slate-700">
                            <span>Raison sociale</span>
                            <input value={customerForm.company_name} onChange={(event) => setCustomerFormField('company_name', event.target.value)} placeholder="Raison sociale *" className="rounded-2xl border border-slate-300 px-4 py-3" />
                        </label>
                        <label className="grid gap-1 text-sm text-slate-700">
                            <span>Contact</span>
                            <input value={customerForm.contact_name} onChange={(event) => setCustomerFormField('contact_name', event.target.value)} placeholder="Contact" className="rounded-2xl border border-slate-300 px-4 py-3" />
                        </label>
                    </>
                )}

                <label className="grid gap-1 text-sm text-slate-700">
                    <span>Téléphone</span>
                    <input value={customerForm.phone} onChange={(event) => setCustomerFormField('phone', event.target.value)} placeholder="Téléphone" className="rounded-2xl border border-slate-300 px-4 py-3" />
                </label>
                <label className="grid gap-1 text-sm text-slate-700">
                    <span>Email</span>
                    <input value={customerForm.email} onChange={(event) => setCustomerFormField('email', event.target.value)} placeholder="Email" className="rounded-2xl border border-slate-300 px-4 py-3" />
                </label>
                {customerType === 'business' && (
                    <label className="grid gap-1 text-sm text-slate-700">
                        <span>ICE / IF / RC</span>
                        <input value={customerForm.tax_identifier} onChange={(event) => setCustomerFormField('tax_identifier', event.target.value)} placeholder="ICE / IF / RC" className="rounded-2xl border border-slate-300 px-4 py-3" />
                    </label>
                )}
                <label className="grid gap-1 text-sm text-slate-700">
                    <span>Adresse</span>
                    <textarea value={customerForm.billing_address} onChange={(event) => setCustomerFormField('billing_address', event.target.value)} placeholder="Adresse" className="rounded-2xl border border-slate-300 px-4 py-3" rows={3} />
                </label>

                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void (isCreate ? createCustomer() : updateCustomer())}
                        className="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-medium text-white disabled:opacity-50"
                    >
                        {isCreate ? 'Créer le client' : 'Enregistrer les modifications'}
                    </button>
                    {!isCreate && (
                        <button type="button" onClick={() => setShowEdit(false)} className="rounded-2xl border border-slate-300 px-4 py-3 text-sm font-medium text-slate-700">
                            Annuler
                        </button>
                    )}
                </div>
            </div>
        );
    }

    return (
        <div className="fixed inset-0 z-50 bg-slate-950/45 p-4 sm:p-6">
            <div className="mx-auto flex h-full max-w-7xl overflow-hidden rounded-[2rem] bg-white shadow-2xl">
                <div className="flex-1 overflow-y-auto p-6 sm:p-8">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs uppercase tracking-[0.2em] text-slate-500">Checkout POS</p>
                            <h2 className="mt-1 text-2xl font-semibold text-slate-950">{sale.order_number}</h2>
                        </div>
                        <button type="button" onClick={onClose} className="rounded-2xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700">Fermer</button>
                    </div>

                    <div className="mt-6 flex flex-wrap gap-2">
                        {['1. Client', '2. Retrait / Livraison', '3. Paiement', '4. Confirmation'].map((label, index) => (
                            <button key={label} type="button" onClick={() => setStep(index + 1)} className={`rounded-full px-4 py-2 text-sm font-medium ${step === index + 1 ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'}`}>
                                {label}
                            </button>
                        ))}
                    </div>

                    {error && <p className="mt-4 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

                    {step === 1 && (
                        <section className="mt-6 space-y-5">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-950">Client</h3>
                                <p className="text-sm text-slate-500">Sélectionnez, remplacez ou ajustez rapidement le client sans quitter le POS.</p>
                            </div>

                            {selectedCustomer && (
                                <div className="rounded-3xl border border-emerald-200 bg-emerald-50 p-5">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold text-emerald-950">{selectedCustomer.display_name}</p>
                                            <p className="mt-1 text-sm text-emerald-800">{selectedCustomer.company_name ?? selectedCustomer.phone ?? selectedCustomer.email ?? 'Client sélectionné'}</p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <button type="button" onClick={() => { setShowCustomerPicker((current) => !current); setResults([]); }} className="rounded-2xl border border-emerald-300 bg-white px-4 py-2 text-sm font-medium text-emerald-900">
                                                {showCustomerPicker ? 'Masquer le sélecteur' : 'Changer de client'}
                                            </button>
                                            {canUpdateCustomer && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setCustomerType(customerTypeFor(selectedCustomer));
                                                        setCustomerForm(customerFormFrom(selectedCustomer));
                                                        setShowEdit((current) => !current);
                                                    }}
                                                    className="rounded-2xl border border-emerald-300 bg-white px-4 py-2 text-sm font-medium text-emerald-900"
                                                >
                                                    {showEdit ? 'Fermer l’édition' : 'Modifier'}
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    {showEdit && canUpdateCustomer && renderCustomerForm('edit')}
                                </div>
                            )}

                            {(showCustomerPicker || !selectedCustomer) && (
                                <div className="rounded-3xl border border-slate-200 p-5">
                                    <div className="flex gap-2">
                                        <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Rechercher un client..." className="h-12 min-w-0 flex-1 rounded-2xl border border-slate-300 px-4" />
                                        <button type="button" onClick={() => void lookupCustomer()} className="rounded-2xl border border-slate-300 px-4 py-2 text-sm font-medium">Rechercher</button>
                                    </div>

                                    <div className="mt-4 space-y-2">
                                        {results.map((customer) => (
                                            <button
                                                key={customer.id}
                                                type="button"
                                                onClick={() => void onCustomerSelected(customer).then(() => {
                                                    setCustomerType(customerTypeFor(customer));
                                                    setCustomerForm(customerFormFrom(customer));
                                                    setShowCustomerPicker(false);
                                                    setShowEdit(false);
                                                    setStep(2);
                                                })}
                                                className="block w-full rounded-2xl border border-slate-200 px-4 py-3 text-left hover:bg-slate-50"
                                            >
                                                <p className="font-semibold">{customer.display_name}</p>
                                                <p className="text-sm text-slate-500">{customer.company_name ?? customer.phone ?? customer.email ?? 'Client showroom'}</p>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {canCreateCustomer && (
                                <div className="rounded-3xl border border-slate-200 p-5">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setCustomerType('individual');
                                            setCustomerForm(blankCustomerForm());
                                            setShowCreate((value) => !value);
                                        }}
                                        className="text-sm font-medium text-slate-700 underline"
                                    >
                                        {showCreate ? 'Fermer le nouveau client' : '+ Nouveau client'}
                                    </button>
                                    {showCreate && renderCustomerForm('create')}
                                </div>
                            )}
                        </section>
                    )}

                    {step === 2 && (
                        <section className="mt-6 space-y-5">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-950">Retrait ou livraison</h3>
                                <p className="text-sm text-slate-500">Le stock n’est consommé qu’à la confirmation finale selon le mode choisi.</p>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <button type="button" onClick={() => void setFulfillmentMode('pickup')} className={`rounded-3xl border p-5 text-left ${checkoutState.fulfillment_mode === 'pickup' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white'}`}>
                                    <p className="font-semibold">Retrait immédiat</p>
                                    <p className={`mt-1 text-sm ${checkoutState.fulfillment_mode === 'pickup' ? 'text-slate-200' : 'text-slate-500'}`}>Nécessite un paiement intégral avant remise au client.</p>
                                </button>
                                <button type="button" onClick={() => void setFulfillmentMode('delivery')} className={`rounded-3xl border p-5 text-left ${checkoutState.fulfillment_mode === 'delivery' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white'}`}>
                                    <p className="font-semibold">Livraison</p>
                                    <p className={`mt-1 text-sm ${checkoutState.fulfillment_mode === 'delivery' ? 'text-slate-200' : 'text-slate-500'}`}>Peut rester partiellement réglée si la livraison suit plus tard.</p>
                                </button>
                            </div>

                            {checkoutState.fulfillment_mode === 'delivery' && (
                                <div className="grid gap-3 rounded-3xl border border-slate-200 p-5">
                                    <label className="grid gap-1 text-sm text-slate-700">
                                        <span>Adresse de livraison</span>
                                        <textarea value={checkoutState.delivery_address} onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_address: event.target.value }))} onBlur={() => void saveCheckoutState()} rows={3} className="rounded-2xl border border-slate-300 px-4 py-3" />
                                    </label>
                                    <label className="grid gap-1 text-sm text-slate-700">
                                        <span>Téléphone de livraison</span>
                                        <input value={checkoutState.delivery_phone} onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_phone: event.target.value }))} onBlur={() => void saveCheckoutState()} className="rounded-2xl border border-slate-300 px-4 py-3" />
                                    </label>
                                    <label className="grid gap-1 text-sm text-slate-700">
                                        <span>Frais de livraison</span>
                                        <input value={checkoutState.shipping_fee === '0.0000' ? '' : checkoutState.shipping_fee} onChange={(event) => setCheckoutState((current) => ({ ...current, shipping_fee: event.target.value || '0' }))} onBlur={() => void saveCheckoutState()} placeholder="0.00" inputMode="decimal" className="rounded-2xl border border-slate-300 px-4 py-3" />
                                    </label>
                                    <label className="grid gap-1 text-sm text-slate-700">
                                        <span>Instructions de livraison</span>
                                        <textarea value={checkoutState.delivery_notes} onChange={(event) => setCheckoutState((current) => ({ ...current, delivery_notes: event.target.value }))} onBlur={() => void saveCheckoutState()} rows={3} className="rounded-2xl border border-slate-300 px-4 py-3" />
                                    </label>
                                </div>
                            )}
                        </section>
                    )}

                    {step === 3 && (
                        <section className="mt-6 space-y-5">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-950">Paiement</h3>
                                <p className="text-sm text-slate-500">Chaque ligne représente combien le client paie avec ce moyen. Le montant reçu en espèces reste séparé pour la monnaie.</p>
                            </div>

                            {payments.map((payment, index) => {
                                const compatible = compatibleAccounts(payment.method, financialAccounts);
                                const autoResolvedAccount = compatible.length === 1 ? compatible[0] : null;

                                return (
                                    <div key={index} className="grid gap-4 rounded-3xl border border-slate-200 p-5">
                                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                            <label className="grid gap-1 text-sm text-slate-700">
                                                <span>Moyen de paiement</span>
                                                <select value={payment.method} onChange={(event) => assignSuggestedAmount(index, event.target.value as PaymentMethod | '')} className="rounded-2xl border border-slate-300 px-3 py-3">
                                                    <option value="">Choisir un moyen</option>
                                                    {Object.entries(paymentLabels).map(([value, label]) => (
                                                        <option key={value} value={value}>{label}</option>
                                                    ))}
                                                </select>
                                            </label>

                                            <label className="grid gap-1 text-sm text-slate-700">
                                                <span>Montant payé</span>
                                                <input
                                                    value={payment.amount}
                                                    onChange={(event) => setPayments((current) => current.map((item, itemIndex) => {
                                                        if (itemIndex !== index) {
                                                            return item;
                                                        }

                                                        return {
                                                            ...item,
                                                            amount: event.target.value,
                                                            amountTouched: true,
                                                            cash_received: item.method === 'cash' && !item.cashReceivedTouched ? event.target.value : item.cash_received,
                                                        };
                                                    }))}
                                                    placeholder="0.00"
                                                    inputMode="decimal"
                                                    className="rounded-2xl border border-slate-300 px-4 py-3"
                                                />
                                            </label>

                                            {payment.method === 'cash' ? (
                                                <label className="grid gap-1 text-sm text-slate-700">
                                                    <span>Montant reçu du client</span>
                                                    <input value={payment.cash_received} onChange={(event) => setPayments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, cash_received: event.target.value, cashReceivedTouched: true } : item))} placeholder="0.00" inputMode="decimal" className="rounded-2xl border border-slate-300 px-4 py-3" />
                                                </label>
                                            ) : (
                                                <label className="grid gap-1 text-sm text-slate-700">
                                                    <span>{payment.method === 'card' ? 'Référence transaction / ticket TPE' : payment.method === 'bank_transfer' ? 'Référence du virement' : 'Numéro du chèque'}</span>
                                                    <input value={payment.reference} onChange={(event) => setPayments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, reference: event.target.value } : item))} placeholder="Optionnelle" className="rounded-2xl border border-slate-300 px-4 py-3" />
                                                </label>
                                            )}

                                            <div className="flex items-end justify-end">
                                                {payments.length > 1 && (
                                                    <button type="button" onClick={() => setPayments((current) => current.filter((_, itemIndex) => itemIndex !== index))} className="text-sm text-red-700">
                                                        Supprimer ce paiement
                                                    </button>
                                                )}
                                            </div>
                                        </div>

                                        {!!payment.method && compatible.length > 1 && (
                                            <label className="grid gap-1 text-sm text-slate-700 md:max-w-lg">
                                                <span>Compte de réception</span>
                                                <select value={payment.financial_account_id} onChange={(event) => setPayments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, financial_account_id: event.target.value ? Number(event.target.value) : '' } : item))} className="rounded-2xl border border-slate-300 px-3 py-3">
                                                    <option value="">Sélectionner un compte compatible</option>
                                                    {compatible.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}
                                                </select>
                                            </label>
                                        )}

                                        {!!payment.method && compatible.length === 1 && autoResolvedAccount && (
                                            <div className="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                                Compte financier: <strong className="text-slate-900">{autoResolvedAccount.code} · {autoResolvedAccount.name}</strong>
                                            </div>
                                        )}

                                        {!!payment.method && compatible.length === 0 && (
                                            <div className="rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                                <p>Aucun compte financier actif n’est compatible avec ce moyen de paiement.</p>
                                                <Link href="/financial-accounts" className="mt-2 inline-flex text-sm font-medium underline">
                                                    Configurer les comptes financiers
                                                </Link>
                                            </div>
                                        )}

                                        {payment.method === 'cash' && (
                                            <p className={`text-sm ${positiveAmount(payment.cash_received) < positiveAmount(payment.amount) ? 'text-red-700' : 'text-slate-500'}`}>
                                                Monnaie à rendre: {formatMoney((Math.max(0, (Number(payment.cash_received) || 0) - positiveAmount(payment.amount))).toFixed(4), currencyCode)}
                                            </p>
                                        )}
                                    </div>
                                );
                            })}

                            <button type="button" onClick={() => setPayments((current) => [...current, buildEmptyPayment()])} className="rounded-2xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">
                                + Ajouter un autre paiement
                            </button>

                            <div className="grid gap-2 rounded-3xl bg-slate-50 p-5 text-sm">
                                <div className="flex items-center justify-between"><span>Montant dû</span><strong>{formatMoney(currentTotal, currencyCode)}</strong></div>
                                <div className="flex items-center justify-between"><span>Total payé</span><strong>{formatMoney(paid.toFixed(4), currencyCode)}</strong></div>
                                <div className="flex items-center justify-between"><span>Reste</span><strong>{formatMoney(remaining, currencyCode)}</strong></div>
                                {checkoutState.fulfillment_mode === 'pickup' && Number(remaining) > 0 && (
                                    <p className="mt-2 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                        Le retrait immédiat exige un paiement intégral avant confirmation.
                                    </p>
                                )}
                                {cashValidationErrors.map((message) => (
                                    <p key={message} className="mt-2 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700">{message}</p>
                                ))}
                                {paymentConfigurationErrors.map((message) => (
                                    <p key={message} className="mt-2 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{message}</p>
                                ))}
                            </div>
                        </section>
                    )}

                    {step === 4 && (
                        <section className="mt-6 space-y-5">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-950">Confirmation</h3>
                                <p className="text-sm text-slate-500">Résumé final avant l’exécution du workflow métier autoritaire.</p>
                            </div>
                            <div className="rounded-3xl border border-slate-200 p-5">
                                <div className="space-y-2 text-sm">
                                    <div className="flex justify-between"><span>Client</span><strong>{selectedCustomer?.display_name ?? 'Client comptoir'}</strong></div>
                                    <div className="flex justify-between"><span>Mode</span><strong>{checkoutState.fulfillment_mode === 'delivery' ? 'Livraison' : 'Retrait immédiat'}</strong></div>
                                    <div className="flex justify-between"><span>Sous-total</span><strong>{formatMoney(sale.summary.merchandise_total, currencyCode)}</strong></div>
                                    <div className="flex justify-between"><span>Remise</span><strong>- {formatMoney((Number(sale.summary.line_discount_total) + Number(sale.summary.global_discount_amount)).toFixed(4), currencyCode)}</strong></div>
                                    <div className="flex justify-between"><span>Livraison</span><strong>{formatMoney(shippingNumeric.toFixed(4), currencyCode)}</strong></div>
                                    <div className="flex justify-between border-t border-slate-200 pt-2 text-base"><span>Total</span><strong>{formatMoney(currentTotal, currencyCode)}</strong></div>
                                    <div className="flex justify-between"><span>Payé</span><strong>{formatMoney(paid.toFixed(4), currencyCode)}</strong></div>
                                    <div className="flex justify-between"><span>Reste</span><strong>{formatMoney(remaining, currencyCode)}</strong></div>
                                </div>

                                {checkoutState.fulfillment_mode === 'delivery' && (
                                    <div className="mt-5 space-y-2 rounded-2xl bg-slate-50 p-4 text-sm">
                                        <p className="font-medium text-slate-900">Détails de livraison</p>
                                        <div className="flex justify-between gap-4"><span>Adresse</span><span className="text-right">{checkoutState.delivery_address || '—'}</span></div>
                                        <div className="flex justify-between gap-4"><span>Téléphone</span><span className="text-right">{checkoutState.delivery_phone || '—'}</span></div>
                                        <div className="flex justify-between gap-4"><span>Instructions</span><span className="text-right">{checkoutState.delivery_notes || '—'}</span></div>
                                    </div>
                                )}

                                <div className="mt-5 space-y-2 rounded-2xl bg-slate-50 p-4 text-sm">
                                    <p className="font-medium text-slate-900">Paiements saisis</p>
                                    {paymentBreakdown.length > 0 ? paymentBreakdown.map((payment, index) => {
                                        const method = payment.method as PaymentMethod;
                                        const account = compatibleAccounts(method, financialAccounts).find((item) => item.id === payment.financial_account_id);

                                        return (
                                            <div key={`${payment.method}-${index}`} className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                                <div className="flex items-center justify-between gap-4">
                                                    <span>{paymentLabels[method]}</span>
                                                    <strong>{formatMoney(payment.amount, currencyCode)}</strong>
                                                </div>
                                                <div className="mt-1 text-xs text-slate-500">
                                                    {account ? `${account.code} · ${account.name}` : 'Compte à confirmer'}
                                                    {payment.method === 'cash' && payment.cash_received ? ` · Reçu ${formatMoney(payment.cash_received, currencyCode)}` : ''}
                                                    {payment.method !== 'cash' && payment.reference ? ` · Réf. ${payment.reference}` : ''}
                                                </div>
                                            </div>
                                        );
                                    }) : <p className="text-slate-500">Aucun paiement saisi.</p>}
                                </div>

                                <div className="mt-5 space-y-2">
                                    {sale.lines.map((line) => <div key={line.id} className="flex items-center justify-between text-sm"><span>{line.description} × {Math.trunc(Number(line.quantity))}</span><strong>{formatMoney(line.line_total ?? '0', currencyCode)}</strong></div>)}
                                </div>
                            </div>
                        </section>
                    )}

                    <div className="mt-8 flex items-center justify-between gap-3">
                        <button type="button" disabled={step === 1} onClick={() => setStep((current) => Math.max(1, current - 1))} className="rounded-2xl border border-slate-300 px-4 py-3 text-sm font-medium text-slate-700 disabled:opacity-40">Retour</button>
                        {step < 4 ? (
                            <button
                                type="button"
                                onClick={async () => {
                                    if (step === 2) {
                                        await saveCheckoutState();
                                    }

                                    if (step === 3 && !validatePaymentStep()) {
                                        return;
                                    }

                                    setStep((current) => Math.min(4, current + 1));
                                }}
                                className="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white"
                            >
                                Continuer
                            </button>
                        ) : (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => {
                                    if (!validatePaymentStep()) {
                                        setStep(3);

                                        return;
                                    }

                                    void onComplete({
                                        fulfillment_mode: checkoutState.fulfillment_mode,
                                        payments: payments
                                            .filter((payment) => payment.method !== '' || payment.amount !== '' || payment.reference !== '' || payment.cash_received !== '')
                                            .map((payment) => ({
                                                method: payment.method,
                                                financial_account_id: Number(payment.financial_account_id),
                                                amount: payment.amount,
                                                cash_received: payment.method === 'cash' ? payment.cash_received || payment.amount : null,
                                                reference: payment.reference || null,
                                            })),
                                    });
                                }}
                                className="rounded-2xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                Confirmer la vente
                            </button>
                        )}
                    </div>
                </div>

                <aside className="hidden w-[24rem] border-l border-slate-200 bg-slate-50 p-6 lg:block">
                    <p className="text-xs uppercase tracking-wide text-slate-500">Résumé</p>
                    <h3 className="mt-1 text-lg font-semibold text-slate-950">{sale.lines.length} produit{sale.lines.length > 1 ? 's' : ''}</h3>
                    <div className="mt-6 space-y-3 text-sm">
                        <div className="flex justify-between"><span>Sous-total</span><strong>{formatMoney(sale.summary.merchandise_total, currencyCode)}</strong></div>
                        <div className="flex justify-between"><span>Remise globale</span><strong>- {formatMoney(sale.summary.global_discount_amount, currencyCode)}</strong></div>
                        <div className="flex justify-between"><span>Livraison</span><strong>{formatMoney(shippingNumeric.toFixed(4), currencyCode)}</strong></div>
                        <div className="flex justify-between border-t border-slate-200 pt-3 text-base"><span>Total</span><strong>{formatMoney(currentTotal, currencyCode)}</strong></div>
                        <div className="flex justify-between"><span>Payé</span><strong>{formatMoney(paid.toFixed(4), currencyCode)}</strong></div>
                        <div className="flex justify-between"><span>Reste</span><strong>{formatMoney(remaining, currencyCode)}</strong></div>
                    </div>
                </aside>
            </div>
        </div>
    );
}
