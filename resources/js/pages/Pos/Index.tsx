import { useToast } from '@/components/ui/toast';
import { usePendingKeys } from '@/hooks/usePendingKeys';
import { useSerializedByKey } from '@/hooks/useSerializedByKey';
import PosLayout from '@/layouts/PosLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { posJson } from './api';
import HeldSalesDrawer from './HeldSalesDrawer';
import PosCartPanel from './PosCartPanel';
import PosCatalogue from './PosCatalogue';
import PosCheckoutPanel from './PosCheckoutPanel';
import PosFilters, { emptyFilters, type CatalogueFilters } from './PosFilters';
import PosReceipt from './PosReceipt';
import PosReviewModal from './PosReviewModal';
import PosSuccessToast from './PosSuccessToast';
import type { ProcurementSubmit } from './PosProcurementPanel';
import type { Account, CheckoutState, PosPending, ReviewData } from './pos-shared';
import type { ActiveSale, CartLine, Customer, HeldSale, Option, ProductResult, Supplier, Warehouse } from './types';

type Store = { id: number; name: string; code: string };
type CompletedOrder = {
    id: number;
    order_number: string;
    customer_name: string | null;
    ordered_at?: string;
    subtotal_excl_tax: string;
    discount_total: string;
    tax_total: string;
    total_incl_tax: string;
    currency_code: string;
    status: string;
    fulfillment_status: string;
    payment_status: string;
    paid: string;
    remaining: string;
    requires_replenishment: boolean;
    remote_required: string;
    payments: { id: number; payment_number: string; method: string; amount: string; reference: string | null; account: { name: string; code: string; type: string }; cash_received: string | null; change: string | null }[];
    lines: { id: number; description: string; quantity: string; unit_price_incl_tax: string; line_total: string }[];
};
type DraftStatePayload = { active_sale: ActiveSale | null; held_sales: HeldSale[] };

type Props = {
    store: Store | null;
    warehouses: Warehouse[];
    currencyCode: string;
    defaultWarehouseId: number | null;
    activeSale: ActiveSale | null;
    heldSales: HeldSale[];
    brands: Option[];
    categories: Option[];
    suppliers: Supplier[];
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
        manageProcurement: boolean;
    };
    financialAccounts: Account[];
    createdCustomer: Customer | null;
    completedOrder: CompletedOrder | null;
};

const PRINT_FLAG = 'pos.print_receipt';

export default function PosIndex({
    store,
    warehouses,
    currencyCode,
    defaultWarehouseId,
    activeSale: initialActiveSale,
    heldSales: initialHeldSales,
    brands,
    categories,
    suppliers,
    can,
    financialAccounts,
    completedOrder,
}: Props) {
    const toast = useToast();
    const pending = usePendingKeys();
    const serialized = useSerializedByKey();

    const [warehouseId, setWarehouseId] = useState<number | null>(initialActiveSale?.warehouse?.id ?? defaultWarehouseId ?? null);
    const [activeSale, setActiveSale] = useState<ActiveSale | null>(initialActiveSale);
    const [heldSales, setHeldSales] = useState<HeldSale[]>(initialHeldSales);
    const [filters, setFilters] = useState<CatalogueFilters>(emptyFilters);
    const [panelMode, setPanelMode] = useState<'cart' | 'checkout'>('cart');
    const [reviewData, setReviewData] = useState<ReviewData | null>(null);
    const [reviewError, setReviewError] = useState<string | null>(null);
    const [completing, setCompleting] = useState(false);
    const [showHeld, setShowHeld] = useState(false);
    const [successVisible, setSuccessVisible] = useState(true);

    // Immediate, optimistic quantity per line so +/- feels instant even while the
    // authoritative server write is in flight. Cleared once the server response
    // for that exact value lands (server value then becomes the source of truth).
    const [optimisticQty, setOptimisticQty] = useState<Record<number, number>>({});
    // Product ids showing a brief "added" pulse on their catalogue card.
    const [addedFlash, setAddedFlash] = useState<Record<number, number>>({});

    const activeSaleRef = useRef<ActiveSale | null>(initialActiveSale);
    const ensureDraftRef = useRef<Promise<ActiveSale> | null>(null);
    const operationIdRef = useRef<string>('');
    activeSaleRef.current = activeSale;

    useEffect(() => {
        setActiveSale(initialActiveSale);
        setHeldSales(initialHeldSales);
        setWarehouseId(initialActiveSale?.warehouse?.id ?? defaultWarehouseId ?? null);
        setOptimisticQty({});
    }, [initialActiveSale, initialHeldSales, defaultWarehouseId]);

    // On completion the server redirects back with `completedOrder`; reset the panel
    // and honour a pending "print" request from "Valider & imprimer".
    useEffect(() => {
        if (!completedOrder) return;
        setPanelMode('cart');
        setReviewData(null);
        setReviewError(null);
        setCompleting(false);
        setSuccessVisible(true);
        let printed = false;
        try {
            if (sessionStorage.getItem(PRINT_FLAG) === '1') {
                sessionStorage.removeItem(PRINT_FLAG);
                printed = true;
            }
        } catch {
            /* storage unavailable */
        }
        if (printed) {
            toast.info('Préparation du reçu…', { duration: 1400 });
            window.setTimeout(() => window.print(), 150);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [completedOrder]);

    const sync = useCallback((payload: DraftStatePayload) => {
        activeSaleRef.current = payload.active_sale;
        setActiveSale(payload.active_sale);
        setHeldSales(payload.held_sales);
    }, []);

    /** One-shot POS action with a scoped pending key + toast error surface. */
    const perform = useCallback(
        async (key: string, fn: () => Promise<void>, options: { success?: string } = {}) => {
            const outcome = await pending.run(key, async () => {
                try {
                    await fn();
                    if (options.success) toast.success(options.success);
                    return true;
                } catch (error) {
                    toast.error(posErrorMessage(error));
                    return false;
                }
            });
            return outcome === true;
        },
        [pending, toast],
    );

    async function ensureDraft(): Promise<ActiveSale> {
        if (activeSaleRef.current) return activeSaleRef.current;
        if (ensureDraftRef.current) return ensureDraftRef.current;
        if (!warehouseId) throw new Error('Sélectionnez un entrepôt opérationnel.');

        const creation = (async () => {
            const payload = await posJson<DraftStatePayload>('/pos/drafts', { method: 'POST', body: { warehouse_id: warehouseId } });
            sync(payload);
            if (!payload.active_sale) throw new Error('Impossible d’ouvrir une nouvelle vente.');
            return payload.active_sale;
        })();
        ensureDraftRef.current = creation;
        try {
            return await creation;
        } finally {
            ensureDraftRef.current = null;
        }
    }

    function currentLine(lineId: number, fallback: CartLine): CartLine {
        return activeSaleRef.current?.lines.find((line) => line.id === lineId) ?? fallback;
    }

    function lineBody(line: CartLine, overrides: Record<string, string | number | null>) {
        return {
            line_type: line.line_type,
            product_variant_id: line.product_variant_id ?? null,
            warehouse_id: activeSaleRef.current?.warehouse?.id ?? warehouseId ?? null,
            description: line.line_type === 'custom' ? line.description : null,
            reference: line.reference ?? null,
            unit_label: line.unit_label ?? null,
            quantity: canonicalQuantity(line.quantity),
            unit_price_excl_tax: line.line_type === 'custom' ? line.unit_price_excl_tax : null,
            tax_rate_id: line.tax_rate_id ?? null,
            discount_type: line.discount_type,
            discount_value: line.discount_value,
            ...overrides,
        };
    }

    function addProduct(product: ProductResult) {
        void perform(`product:add:${product.id}`, async () => {
            const draft = await ensureDraft();
            sync(
                await posJson<DraftStatePayload>(`/pos/drafts/${draft.id}/lines`, {
                    method: 'POST',
                    body: { line_type: 'catalog', product_variant_id: product.id, warehouse_id: warehouseId, quantity: '1', discount_type: 'none', discount_value: '0.0000' },
                }),
            );
            const stamp = Date.now();
            setAddedFlash((current) => ({ ...current, [product.id]: stamp }));
            window.setTimeout(() => {
                setAddedFlash((current) => (current[product.id] === stamp ? omitKey(current, product.id) : current));
            }, 1000);
        });
    }

    /** Serialised, latest-wins per line: rapid +/- or discount edits never land out of order. */
    function enqueueLineWrite(lineId: number, overrides: Record<string, string | number | null>, optimistic?: number) {
        const key = `line:update:${lineId}`;
        if (optimistic !== undefined) {
            setOptimisticQty((current) => ({ ...current, [lineId]: optimistic }));
        }
        serialized.enqueue(key, async () => {
            pending.start(key);
            try {
                const line = currentLine(lineId, { id: lineId } as CartLine);
                sync(await posJson<DraftStatePayload>(`/pos/drafts/${activeSaleRef.current?.id}/lines/${lineId}`, { method: 'PATCH', body: lineBody(line, overrides) }));
                if (optimistic !== undefined) {
                    setOptimisticQty((current) => (current[lineId] === optimistic ? omitKey(current, lineId) : current));
                }
            } catch (error) {
                setOptimisticQty((current) => omitKey(current, lineId));
                toast.error(posErrorMessage(error));
            } finally {
                pending.stop(key);
            }
        });
    }

    function changeQuantity(line: CartLine, quantity: number) {
        if (!activeSaleRef.current) return;
        const target = Math.max(1, Math.trunc(quantity) || 1);
        enqueueLineWrite(line.id, { quantity: String(target) }, target);
    }

    function changeLineDiscount(line: CartLine, type: CartLine['discount_type'], value: string) {
        if (!activeSaleRef.current) return;
        enqueueLineWrite(line.id, { discount_type: type, discount_value: type === 'none' ? '0' : value });
    }

    function removeLine(line: CartLine) {
        if (!activeSaleRef.current) return;
        void perform(`line:remove:${line.id}`, async () => {
            setOptimisticQty((current) => omitKey(current, line.id));
            sync(await posJson<DraftStatePayload>(`/pos/drafts/${activeSaleRef.current?.id}/lines/${line.id}`, { method: 'DELETE' }));
        });
    }

    async function updateDraftFields(fields: Record<string, string | null>) {
        if (!activeSaleRef.current) return;
        sync(await posJson<DraftStatePayload>(`/pos/drafts/${activeSaleRef.current.id}`, { method: 'PATCH', body: fields }));
    }

    /** Re-pull the active draft (and its supplier-procurement state) from the server. */
    async function refreshDraft() {
        if (!warehouseId) return;
        sync(await posJson<DraftStatePayload>('/pos/drafts', { method: 'POST', body: { warehouse_id: warehouseId } }));
    }

    /**
     * Create (or update) the supplier procurement for one under-covered cart line
     * through the SHARED procurement endpoints, then refresh the cart. POS and the
     * back-office act on the same SalesOrderProcurement rows.
     */
    async function submitProcurement(payload: ProcurementSubmit) {
        const sale = activeSaleRef.current;
        if (!sale) return;
        await perform(
            'procurement:save',
            async () => {
                const existing = sale.lines.find((line) => line.id === payload.sales_order_line_id)?.procurement ?? null;
                let procurementId = existing?.id ?? null;

                if (!procurementId) {
                    const created = await posJson<{ data: { id: number } }>(`/sales/orders/${sale.id}/procurements`, {
                        method: 'POST',
                        body: {
                            sales_order_line_id: payload.sales_order_line_id,
                            supplier_id: payload.supplier_id,
                            quantity: payload.quantity,
                            supplier_reference: payload.supplier_reference ?? null,
                        },
                    });
                    procurementId = created.data.id;
                } else if (existing && existing.supplier?.id !== payload.supplier_id) {
                    await posJson(`/procurement/${procurementId}/supplier`, {
                        method: 'PATCH',
                        body: { supplier_id: payload.supplier_id },
                    });
                }

                if (procurementId) {
                    await posJson(`/procurement/${procurementId}/availability`, {
                        method: 'PATCH',
                        body: {
                            supplier_availability_status: payload.supplier_availability_status,
                            quantity: payload.quantity,
                            supplier_reference: payload.supplier_reference ?? null,
                        },
                    });
                }

                await refreshDraft();
            },
            { success: 'Approvisionnement fournisseur enregistré' },
        );
    }

    function changeGlobalDiscount(type: 'none' | 'fixed' | 'percentage', value: string) {
        const key = 'discount:global';
        serialized.enqueue(key, async () => {
            pending.start(key);
            try {
                await updateDraftFields({ global_discount_type: type, global_discount_value: type === 'none' ? '0' : value });
            } catch (error) {
                toast.error(posErrorMessage(error));
            } finally {
                pending.stop(key);
            }
        });
    }

    function holdCurrent(startNewSale = false) {
        if (!activeSaleRef.current) return;
        void perform(
            'sale:hold',
            async () => {
                sync(await posJson<DraftStatePayload>(`/pos/drafts/${activeSaleRef.current?.id}/hold`, { method: 'POST', body: { start_new_sale: startNewSale, warehouse_id: warehouseId } }));
                setPanelMode('cart');
                setOptimisticQty({});
            },
            { success: 'Vente mise en attente' },
        );
    }

    function createNewSale() {
        if (activeSaleRef.current && activeSaleRef.current.lines.length > 0) {
            if (!window.confirm('La vente active contient des articles. La mettre en attente et démarrer une nouvelle vente ?')) return;
            holdCurrent(true);
            return;
        }
        void perform('sale:new', async () => {
            sync(await posJson<DraftStatePayload>('/pos/drafts', { method: 'POST', body: { warehouse_id: warehouseId } }));
            setPanelMode('cart');
            setOptimisticQty({});
        });
    }

    function resumeHeldSale(sale: HeldSale) {
        void perform(
            `sale:resume:${sale.id}`,
            async () => {
                sync(await posJson<DraftStatePayload>(`/pos/drafts/${sale.id}/resume`, { method: 'POST' }));
                setShowHeld(false);
                setPanelMode('cart');
                setOptimisticQty({});
            },
            { success: `Vente ${sale.order_number} reprise` },
        );
    }

    function deleteHeldSale(sale: HeldSale) {
        if (!window.confirm(`Supprimer la vente en attente ${sale.order_number} ?`)) return;
        void perform(`sale:delete:${sale.id}`, async () => {
            sync(await posJson<DraftStatePayload>(`/pos/drafts/${sale.id}`, { method: 'DELETE' }));
        });
    }

    async function selectCustomer(customer: Customer) {
        if (!activeSaleRef.current) {
            try {
                await ensureDraft();
            } catch (error) {
                toast.error(posErrorMessage(error));
                return;
            }
        }
        await perform('customer:attach', () => updateDraftFields({ customer_id: String(customer.id) }));
    }

    function detachCustomer() {
        void perform('customer:detach', () => updateDraftFields({ customer_id: null }));
    }

    async function updateCustomerLocal(customer: Customer) {
        setActiveSale((current) => (current ? { ...current, customer } : current));
    }

    async function saveCheckoutState(state: CheckoutState) {
        await perform('checkout:save', () =>
            updateDraftFields({
                fulfillment_mode: state.fulfillment_mode,
                shipping_fee: state.shipping_fee || '0',
                delivery_address: state.delivery_address || null,
                delivery_phone: state.delivery_phone || null,
                delivery_notes: state.delivery_notes || null,
            }),
        );
    }

    function openReview(data: ReviewData) {
        operationIdRef.current = crypto.randomUUID();
        setReviewError(null);
        setReviewData(data);
    }

    function confirmSale(print: boolean) {
        if (completing || !activeSaleRef.current || !reviewData) return;
        setCompleting(true);
        setReviewError(null);
        try {
            if (print) sessionStorage.setItem(PRINT_FLAG, '1');
        } catch {
            /* storage unavailable */
        }
        router.post(
            '/pos/sales',
            {
                client_operation_id: operationIdRef.current,
                order_id: activeSaleRef.current.id,
                fulfillment_mode: reviewData.fulfillment_mode,
                payments: reviewData.payments.map((payment) => ({
                    method: payment.method,
                    financial_account_id: payment.financial_account_id,
                    amount: payment.amount,
                    cash_received: payment.method === 'cash' ? payment.cash_received ?? payment.amount : null,
                    reference: payment.reference,
                })),
            },
            {
                preserveScroll: true,
                onError: (pageErrors) => {
                    // Keep the review open with the specific error so the cashier can
                    // correct and retry — the cart and payment context are untouched.
                    setCompleting(false);
                    try {
                        sessionStorage.removeItem(PRINT_FLAG);
                    } catch {
                        /* noop */
                    }
                    const messages = Object.values(pageErrors).flatMap((value) => (Array.isArray(value) ? value : [value]));
                    setReviewError(messages[0] ?? 'La vente n’a pas pu être enregistrée.');
                },
            },
        );
    }

    const posPending: PosPending = {
        line: (lineId) => pending.isPending(`line:update:${lineId}`),
        removing: (lineId) => pending.isPending(`line:remove:${lineId}`),
        globalDiscount: pending.isPending('discount:global'),
        recalculating: pending.anyPending('line:update:') || pending.isPending('discount:global') || pending.isPending('checkout:save'),
        hold: pending.isPending('sale:hold') || pending.isPending('sale:new'),
    };

    // Cart lines decorated with any optimistic quantity for instant feedback.
    const decoratedSale = useMemo<ActiveSale | null>(() => {
        if (!activeSale) return null;
        if (Object.keys(optimisticQty).length === 0) return activeSale;
        return {
            ...activeSale,
            lines: activeSale.lines.map((line) => (optimisticQty[line.id] !== undefined ? { ...line, quantity: String(optimisticQty[line.id]) } : line)),
        };
    }, [activeSale, optimisticQty]);

    if (!store) {
        return (
            <PosLayout>
                <Head title="Point de vente" />
                <div className="flex h-full items-center justify-center p-6">
                    <div className="max-w-md rounded-card border border-line bg-surface p-6 shadow-card">
                        <h2 className="text-lg font-semibold text-ink">Sélectionnez un magasin actif</h2>
                        <p className="mt-2 text-sm text-ink-muted">Choisissez une organisation et un magasin depuis la barre supérieure pour ouvrir le point de vente.</p>
                        <Link href="/platform" className="mt-4 inline-flex rounded-field bg-primary px-4 py-2 text-sm font-medium text-primary-fg">
                            Retour au tableau de bord
                        </Link>
                    </div>
                </div>
            </PosLayout>
        );
    }

    return (
        <PosLayout>
            <Head title="Point de vente" />

            <div className="flex h-full min-h-0 flex-col gap-2 p-3">
                {/* Slim workspace strip */}
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <label className="flex items-center gap-2 text-[12px] text-ink-muted">
                        Entrepôt
                        <select
                            value={warehouseId ?? ''}
                            onChange={(event) => setWarehouseId(event.target.value ? Number(event.target.value) : null)}
                            className="h-8 rounded-field border border-line-strong bg-surface px-2 text-[13px] text-ink outline-none focus:border-primary"
                        >
                            <option value="">Sélectionner…</option>
                            {warehouses.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>{warehouse.name} · {warehouse.code}</option>
                            ))}
                        </select>
                    </label>
                </div>

                {/* 3-zone workspace */}
                <div className="flex min-h-0 flex-1 gap-3">
                    <PosFilters brands={brands} categories={categories} value={filters} onChange={setFilters} />

                    <div className="flex min-h-0 min-w-0 flex-1">
                        <PosCatalogue
                            warehouseId={warehouseId}
                            filters={filters}
                            isAdding={(id) => pending.isPending(`product:add:${id}`)}
                            justAdded={(id) => addedFlash[id] !== undefined}
                            onAdd={addProduct}
                        />
                    </div>

                    <div className="flex w-[26rem] shrink-0 flex-col overflow-hidden rounded-card border border-line bg-surface shadow-card">
                        {panelMode === 'cart' || !activeSale ? (
                            <PosCartPanel
                                sale={decoratedSale}
                                currencyCode={currencyCode}
                                pending={posPending}
                                canApplyDiscount={can.applyDiscount}
                                heldCount={heldSales.length}
                                suppliers={suppliers}
                                canManageProcurement={can.manageProcurement}
                                procurementBusy={pending.isPending('procurement:save')}
                                onProcurementSubmit={submitProcurement}
                                onQuantityChange={changeQuantity}
                                onDiscountChange={changeLineDiscount}
                                onRemove={removeLine}
                                onGlobalDiscountChange={changeGlobalDiscount}
                                onHold={() => holdCurrent(false)}
                                onNewSale={createNewSale}
                                onOpenHeld={() => setShowHeld(true)}
                                onCheckout={() => setPanelMode('checkout')}
                            />
                        ) : (
                            <PosCheckoutPanel
                                sale={activeSale}
                                currencyCode={currencyCode}
                                financialAccounts={financialAccounts}
                                canCreateCustomer={can.createCustomer}
                                canUpdateCustomer={can.updateCustomer}
                                savingCheckout={pending.isPending('checkout:save')}
                                attachingCustomer={pending.anyPending('customer:')}
                                onBack={() => setPanelMode('cart')}
                                onCustomerSelected={selectCustomer}
                                onCustomerUpdated={updateCustomerLocal}
                                onCustomerDetach={detachCustomer}
                                onCheckoutStateSave={saveCheckoutState}
                                onReview={openReview}
                            />
                        )}
                    </div>
                </div>
            </div>

            <HeldSalesDrawer
                open={showHeld}
                sales={heldSales}
                resumingId={(id) => pending.isPending(`sale:resume:${id}`)}
                deletingId={(id) => pending.isPending(`sale:delete:${id}`)}
                onClose={() => setShowHeld(false)}
                onResume={resumeHeldSale}
                onDelete={deleteHeldSale}
            />

            {activeSale && (
                <PosReviewModal
                    open={reviewData !== null}
                    data={reviewData}
                    sale={activeSale}
                    currencyCode={currencyCode}
                    busy={completing}
                    error={reviewError}
                    onBack={() => {
                        setReviewData(null);
                        setReviewError(null);
                    }}
                    onConfirm={confirmSale}
                />
            )}

            {completedOrder && successVisible && (
                <PosSuccessToast
                    order={completedOrder}
                    onPrint={() => window.print()}
                    onNewSale={createNewSale}
                    onDismiss={() => setSuccessVisible(false)}
                />
            )}

            {completedOrder && (
                <PosReceipt
                    storeName={store.name}
                    order={{
                        order_number: completedOrder.order_number,
                        customer_name: completedOrder.customer_name,
                        ordered_at: completedOrder.ordered_at,
                        subtotal_excl_tax: completedOrder.subtotal_excl_tax,
                        discount_total: completedOrder.discount_total,
                        tax_total: completedOrder.tax_total,
                        total_incl_tax: completedOrder.total_incl_tax,
                        currency_code: completedOrder.currency_code,
                        paid: completedOrder.paid,
                        remaining: completedOrder.remaining,
                        payments: completedOrder.payments,
                    }}
                    lines={completedOrder.lines}
                />
            )}
        </PosLayout>
    );
}

/**
 * The POS line endpoint requires `quantity` on every update and validates it
 * against `^[1-9]\d*$` (whole physical units). The draft payload exposes it as a
 * DECIMAL(19,4) string ("2.0000"), so it must be sent back in canonical integer
 * form — never the display/server-cast value.
 */
function canonicalQuantity(value: string | number): string {
    const whole = Math.trunc(Number(value));
    return String(Number.isFinite(whole) && whole >= 1 ? whole : 1);
}

function omitKey<T extends Record<number, unknown>>(source: T, key: number): T {
    if (source[key] === undefined) return source;
    const next = { ...source };
    delete next[key];
    return next;
}

function posErrorMessage(error: unknown): string {
    if (error && typeof error === 'object' && 'errors' in error) {
        const values = Object.values((error as { errors?: Record<string, string[]> }).errors ?? {}).flat();
        if (values.length > 0) return values[0];
    }
    if (error instanceof Error && error.message) return error.message;
    return 'L’opération POS a échoué.';
}
