import PosLayout from '@/layouts/PosLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import HeldSalesDrawer from './HeldSalesDrawer';
import { posJson } from './api';
import PosCart from './PosCart';
import PosCheckoutSummary from './PosCheckoutSummary';
import PosCheckoutWorkspace from './PosCheckoutWorkspace';
import PosProductSearch from './PosProductSearch';
import type { ActiveSale, CartLine, Customer, HeldSale, Option, TaxRate, Warehouse } from './types';

type Store = { id: number; name: string; code: string };
type CompletedOrder = { id: number; order_number: string; customer_name: string | null; total_incl_tax: string; currency_code: string; status: string; fulfillment_status: string; payment_status: string; paid: string; remaining: string; payments: { id: number; payment_number: string; method: string; amount: string; reference: string | null; account: { name: string; code: string; type: string }; cash_received: string | null; change: string | null }[] };
type DraftStatePayload = { active_sale: ActiveSale | null; held_sales: HeldSale[] };
type Props = {
    store: Store | null;
    warehouses: Warehouse[];
    taxRates: TaxRate[];
    currencyCode: string;
    defaultWarehouseId: number | null;
    activeSale: ActiveSale | null;
    heldSales: HeldSale[];
    brands: Option[];
    categories: Option[];
    can: {
        createCustomer: boolean;
        updateCustomer: boolean;
        overridePrice: boolean;
        applyDiscount: boolean;
        createPayment: boolean;
        createDraft: boolean;
        updateDraft: boolean;
        holdDraft: boolean;
        cancelDraft: boolean;
    };
    financialAccounts: { id: number; name: string; code: string; type: string; currency_code: string }[];
    createdCustomer: Customer | null;
    completedOrder: CompletedOrder | null;
};

export default function PosIndex({ store, warehouses, taxRates: _taxRates, currencyCode, defaultWarehouseId, activeSale: initialActiveSale, heldSales: initialHeldSales, brands, categories, can, financialAccounts, createdCustomer, completedOrder }: Props) {
    const [warehouseId, setWarehouseId] = useState<number | null>(initialActiveSale?.warehouse?.id ?? defaultWarehouseId ?? null);
    const [activeSale, setActiveSale] = useState<ActiveSale | null>(initialActiveSale);
    const [heldSales, setHeldSales] = useState<HeldSale[]>(initialHeldSales);
    const [busy, setBusy] = useState(false);
    const [showHeldSales, setShowHeldSales] = useState(false);
    const [showCheckout, setShowCheckout] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);

    useEffect(() => {
        setActiveSale(initialActiveSale);
        setHeldSales(initialHeldSales);
        setWarehouseId(initialActiveSale?.warehouse?.id ?? defaultWarehouseId ?? null);
    }, [initialActiveSale, initialHeldSales, defaultWarehouseId]);

    async function run(action: () => Promise<void>) {
        setBusy(true);
        setErrors([]);

        try {
            await action();
        } catch (error) {
            if (error && typeof error === 'object' && 'errors' in error) {
                setErrors(Object.values((error as { errors?: Record<string, string[]> }).errors ?? {}).flat());
            } else if (error instanceof Error) {
                setErrors([error.message]);
            } else {
                setErrors(['L’opération POS a échoué.']);
            }
        } finally {
            setBusy(false);
        }
    }

    function syncDraftState(payload: DraftStatePayload) {
        setActiveSale(payload.active_sale);
        setHeldSales(payload.held_sales);
    }

    async function ensureDraft() {
        if (activeSale) return activeSale;
        if (!warehouseId) throw new Error('Sélectionnez un entrepôt opérationnel avant de commencer une vente.');

        const payload = await posJson<DraftStatePayload>('/pos/drafts', {
            method: 'POST',
            body: { warehouse_id: warehouseId },
        });
        syncDraftState(payload);

        if (!payload.active_sale) {
            throw new Error('Impossible d’ouvrir une nouvelle vente POS.');
        }

        return payload.active_sale;
    }

    async function addProduct(product: any) {
        await run(async () => {
            const draft = await ensureDraft();
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${draft.id}/lines`, {
                method: 'POST',
                body: {
                    line_type: 'catalog',
                    product_variant_id: product.id,
                    warehouse_id: warehouseId,
                    quantity: '1',
                    discount_type: 'none',
                    discount_value: '0.0000',
                },
            });
            syncDraftState(payload);
        });
    }

    async function changeQuantity(line: CartLine, quantity: number) {
        if (!activeSale) return;

        await run(async () => {
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${activeSale.id}/lines/${line.id}`, {
                method: 'PATCH',
                body: {
                    line_type: line.line_type,
                    product_variant_id: line.product_variant_id ?? null,
                    warehouse_id: activeSale.warehouse?.id ?? warehouseId ?? null,
                    description: line.line_type === 'custom' ? line.description : null,
                    reference: line.reference ?? null,
                    unit_label: line.unit_label ?? null,
                    quantity: String(quantity),
                    unit_price_excl_tax: line.line_type === 'custom' ? line.unit_price_excl_tax : null,
                    tax_rate_id: line.tax_rate_id ?? null,
                    discount_type: line.discount_type,
                    discount_value: line.discount_value,
                },
            });
            syncDraftState(payload);
        });
    }

    async function changeLineDiscount(line: CartLine, discountType: CartLine['discount_type'], discountValue: string) {
        if (!activeSale) return;

        await run(async () => {
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${activeSale.id}/lines/${line.id}`, {
                method: 'PATCH',
                body: {
                    line_type: line.line_type,
                    product_variant_id: line.product_variant_id ?? null,
                    warehouse_id: activeSale.warehouse?.id ?? warehouseId ?? null,
                    description: line.line_type === 'custom' ? line.description : null,
                    reference: line.reference ?? null,
                    unit_label: line.unit_label ?? null,
                    quantity: line.quantity,
                    unit_price_excl_tax: line.line_type === 'custom' ? line.unit_price_excl_tax : null,
                    tax_rate_id: line.tax_rate_id ?? null,
                    discount_type: discountType,
                    discount_value: discountType === 'none' ? '0' : discountValue,
                },
            });
            syncDraftState(payload);
        });
    }

    async function removeLine(line: CartLine) {
        if (!activeSale) return;
        await run(async () => {
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${activeSale.id}/lines/${line.id}`, { method: 'DELETE' });
            syncDraftState(payload);
        });
    }

    async function holdCurrent(startNewSale = false) {
        if (!activeSale) return;
        await run(async () => {
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${activeSale.id}/hold`, {
                method: 'POST',
                body: { start_new_sale: startNewSale, warehouse_id: warehouseId },
            });
            syncDraftState(payload);
            setShowCheckout(false);
        });
    }

    async function createNewSale() {
        if (activeSale && activeSale.lines.length > 0) {
            if (!window.confirm('La vente active contient déjà des articles. La mettre en attente et démarrer une nouvelle vente ?')) return;
            await holdCurrent(true);
            return;
        }

        await run(async () => {
            const payload = await posJson<DraftStatePayload>('/pos/drafts', { method: 'POST', body: { warehouse_id: warehouseId } });
            syncDraftState(payload);
        });
    }

    async function resumeHeldSale(sale: HeldSale) {
        await run(async () => {
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${sale.id}/resume`, { method: 'POST' });
            syncDraftState(payload);
            setShowHeldSales(false);
        });
    }

    async function deleteHeldSale(sale: HeldSale) {
        if (!window.confirm(`Supprimer la vente en attente ${sale.order_number} ?`)) return;
        await run(async () => {
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${sale.id}`, { method: 'DELETE' });
            syncDraftState(payload);
        });
    }

    async function updateDraftFields(fields: Record<string, string | null>) {
        if (!activeSale) return;

        await run(async () => {
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${activeSale.id}`, {
                method: 'PATCH',
                body: fields,
            });
            syncDraftState(payload);
        });
    }

    async function selectCustomer(customer: Customer) {
        if (!activeSale) {
            await ensureDraft();
        }

        const orderId = activeSale?.id ?? (await ensureDraft()).id;

        await run(async () => {
            const payload = await posJson<DraftStatePayload>(`/pos/drafts/${orderId}`, {
                method: 'PATCH',
                body: { customer_id: customer.id },
            });
            syncDraftState(payload);
        });
    }

    async function updateCustomer(customer: Customer) {
        setActiveSale(current => current ? { ...current, customer } : current);
    }

    async function completeSale(payload: { fulfillment_mode: 'pickup' | 'delivery'; payments: Array<{ method: string; financial_account_id: number; amount: string; cash_received?: string | null; reference?: string | null }> }) {
        if (!activeSale) return;

        router.post('/pos/sales', {
            client_operation_id: crypto.randomUUID(),
            order_id: activeSale.id,
            fulfillment_mode: payload.fulfillment_mode,
            payments: payload.payments,
        }, {
            preserveScroll: true,
            onError: (pageErrors) => setErrors(Object.values(pageErrors).flatMap((value) => Array.isArray(value) ? value : [value])),
            onSuccess: () => {
                setShowCheckout(false);
            },
        });
    }

    if (!store) {
        return <PosLayout><Head title="Point of Sale" /><div className="mx-auto max-w-xl px-6 py-20"><div className="rounded-xl border border-amber-200 bg-amber-50 p-6"><h2 className="text-lg font-semibold text-amber-950">Sélectionnez un magasin actif avant d’ouvrir le POS.</h2><p className="mt-2 text-sm text-amber-800">Utilisez la sélection de contexte locataire depuis Platform.</p><Link href="/platform" className="mt-5 inline-flex rounded bg-slate-900 px-4 py-2 text-white">Sélectionner un magasin</Link></div></div></PosLayout>;
    }

    return (
        <PosLayout>
            <Head title="Point of Sale" />
            <div className="mx-auto max-w-[1720px] space-y-5 p-4 sm:p-6">
                {completedOrder && <PosCheckoutSummary order={completedOrder} onNewSale={() => void createNewSale()} />}

                <section className="grid gap-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[1.2fr_auto_auto]">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-500">Showroom POS</p>
                        <p className="mt-1 text-xl font-semibold text-slate-950">{store.name}</p>
                        <p className="text-sm text-slate-500">Les produits apparaissent immédiatement. Le checkout reste dans cet espace POS.</p>
                    </div>
                    <label className="text-sm text-slate-600">
                        Entrepôt opérationnel
                        <select value={warehouseId ?? ''} onChange={(event) => setWarehouseId(event.target.value ? Number(event.target.value) : null)} className="mt-1 block min-w-64 rounded-2xl border border-slate-300 px-3 py-2.5 text-slate-950">
                            <option value="">Sélectionner un entrepôt</option>
                            {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} · {warehouse.code}</option>)}
                        </select>
                    </label>
                    <div className="flex items-end justify-end">
                        <button type="button" onClick={() => setShowHeldSales(true)} className="inline-flex min-h-11 items-center justify-center rounded-2xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700">
                            Ventes en attente ({heldSales.length})
                        </button>
                    </div>
                </section>

                {errors.map((error, index) => <p key={index} className="rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>)}

                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(27rem,0.82fr)]">
                    <PosProductSearch warehouseId={warehouseId} brands={brands} categories={categories} onAdd={(product) => void addProduct(product)} />

                    <PosCart
                        sale={activeSale}
                        currencyCode={currencyCode}
                        busy={busy}
                        canApplyDiscount={can.applyDiscount}
                        onQuantityChange={(line, quantity) => void changeQuantity(line, quantity)}
                        onDiscountChange={(line, type, value) => void changeLineDiscount(line, type, value)}
                        onRemove={(line) => void removeLine(line)}
                        onHold={() => void holdCurrent(false)}
                        onCheckout={() => setShowCheckout(true)}
                        onNewSale={() => void createNewSale()}
                        onCheckoutFieldChange={(field, value) => void updateDraftFields({ [field]: value || null })}
                    />
                </div>
            </div>

            <HeldSalesDrawer open={showHeldSales} sales={heldSales} busy={busy} onClose={() => setShowHeldSales(false)} onResume={(sale) => void resumeHeldSale(sale)} onDelete={(sale) => void deleteHeldSale(sale)} />
            <PosCheckoutWorkspace
                open={showCheckout}
                sale={activeSale}
                currencyCode={currencyCode}
                financialAccounts={financialAccounts}
                canCreateCustomer={can.createCustomer}
                canUpdateCustomer={can.updateCustomer}
                onClose={() => setShowCheckout(false)}
                onCustomerSelected={selectCustomer}
                onCustomerUpdated={updateCustomer}
                onCheckoutStateSave={(state) => updateDraftFields({
                    fulfillment_mode: state.fulfillment_mode,
                    shipping_fee: state.shipping_fee || '0',
                    delivery_address: state.delivery_address || null,
                    delivery_phone: state.delivery_phone || null,
                    delivery_notes: state.delivery_notes || null,
                })}
                onComplete={completeSale}
            />
        </PosLayout>
    );
}
