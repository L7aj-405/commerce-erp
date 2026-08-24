import PosLayout from '@/layouts/PosLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import PosCart, { calculateLine } from './PosCart';
import PosCustomerSelector from './PosCustomerSelector';
import PosProductSearch from './PosProductSearch';
import type { CartLine, Customer, ProductResult, TaxRate, Warehouse } from './types';

type Store = { id: number; name: string; code: string };
type CompletedOrder = { id: number; order_number: string; total_incl_tax: string; currency_code: string; status: string; fulfillment_status: string; payment_status: string };
type RecentSale = { id: number; order_number: string; customer_name: string | null; total_incl_tax: string; currency_code: string; payment_status: string; ordered_at: string };
type Props = {
    store: Store | null;
    warehouses: Warehouse[];
    taxRates: TaxRate[];
    currencyCode: string;
    can: { createCustomer: boolean; overridePrice: boolean; applyDiscount: boolean; addCustomItem: boolean };
    createdCustomer: Customer | null;
    completedOrder: CompletedOrder | null;
    recentSales: RecentSale[];
};

export default function PosIndex({ store, warehouses, taxRates, currencyCode, can, createdCustomer, completedOrder, recentSales }: Props) {
    const [warehouseId, setWarehouseId] = useState<number | null>(warehouses[0]?.id ?? null);
    const [customer, setCustomer] = useState<Customer | null>(createdCustomer);
    const [lines, setLines] = useState<CartLine[]>([]);
    const [operationId, setOperationId] = useState(() => crypto.randomUUID());
    const [showCustom, setShowCustom] = useState(false);
    const [showConfirmation, setShowConfirmation] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);

    useEffect(() => {
        if (createdCustomer) setCustomer(createdCustomer);
    }, [createdCustomer]);

    useEffect(() => {
        function close(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setShowConfirmation(false);
                setShowCustom(false);
            }
        }
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, []);

    const selectedWarehouse = warehouses.find((warehouse) => warehouse.id === warehouseId) ?? null;
    const totals = useMemo(() => lines.reduce((sum, line) => {
        const value = calculateLine(line);
        return { subtotal: sum.subtotal + value.subtotal, discount: sum.discount + value.discount, tax: sum.tax + value.tax, total: sum.total + value.total };
    }, { subtotal: 0, discount: 0, tax: 0, total: 0 }), [lines]);

    function resetCart() {
        setLines([]);
        setCustomer(null);
        setOperationId(crypto.randomUUID());
        setErrors([]);
        setShowConfirmation(false);
    }

    function changeWarehouse(nextId: number) {
        if (lines.length > 0 && !window.confirm('Changing Warehouse clears the current cart. Continue?')) return;
        setWarehouseId(nextId);
        resetCart();
    }

    function addProduct(product: ProductResult) {
        setLines((current) => {
            const existing = current.find((line) => line.line_type === 'catalog' && line.product_variant_id === product.id && line.discount_type === 'none' && line.unit_price_excl_tax === product.default_sale_price);
            if (existing) {
                return current.map((line) => line.key === existing.key ? { ...line, quantity: (Number(line.quantity) + 1).toFixed(4), available: product.stock.available } : line);
            }
            return [...current, {
                key: `catalog-${product.id}-${crypto.randomUUID()}`,
                line_type: 'catalog', product_variant_id: product.id, description: product.product_name,
                variant_name: product.variant_name, sku: product.sku, reference: product.reference,
                quantity: '1.0000', unit_price_excl_tax: product.default_sale_price,
                tax_rate_id: product.tax_rate?.id ?? null, tax_rate: product.tax_rate?.rate ?? '0.0000',
                discount_type: 'none', discount_value: '0.0000', available: product.stock.available,
            }];
        });
    }

    function addCustomItem(form: HTMLFormElement) {
        const data = new FormData(form);
        const taxId = data.get('tax_rate_id') ? Number(data.get('tax_rate_id')) : null;
        const tax = taxRates.find((item) => item.id === taxId);
        setLines((current) => [...current, {
            key: `custom-${crypto.randomUUID()}`,
            line_type: 'custom', description: String(data.get('description') ?? ''), reference: String(data.get('reference') ?? '') || null,
            unit_label: String(data.get('unit_label') ?? '') || null, quantity: String(data.get('quantity') ?? '1'),
            unit_price_excl_tax: String(data.get('unit_price_excl_tax') ?? '0'), tax_rate_id: taxId,
            tax_rate: tax?.rate ?? '0.0000', discount_type: 'none', discount_value: '0.0000',
        }]);
        form.reset();
        setShowCustom(false);
    }

    function completeSale() {
        if (!warehouseId || lines.length === 0) return;
        setProcessing(true);
        setErrors([]);
        router.post('/pos/sales', {
            client_operation_id: operationId,
            warehouse_id: warehouseId,
            customer_id: customer?.id ?? null,
            lines: lines.map((line) => ({
                line_type: line.line_type,
                product_variant_id: line.product_variant_id,
                description: line.line_type === 'custom' ? line.description : undefined,
                reference: line.reference,
                unit_label: line.unit_label,
                quantity: line.quantity,
                unit_price_excl_tax: line.line_type === 'custom' || can.overridePrice ? line.unit_price_excl_tax : undefined,
                tax_rate_id: line.line_type === 'custom' ? line.tax_rate_id : undefined,
                discount_type: can.applyDiscount ? line.discount_type : 'none',
                discount_value: can.applyDiscount ? line.discount_value : '0.0000',
            })),
        }, {
            preserveScroll: true,
            onError: (responseErrors) => setErrors(Object.values(responseErrors)),
            onSuccess: resetCart,
            onFinish: () => setProcessing(false),
        });
    }

    if (!store) {
        return <PosLayout><Head title="Point of Sale" /><div className="mx-auto max-w-xl px-6 py-20"><div className="rounded-xl border border-amber-200 bg-amber-50 p-6"><h2 className="text-lg font-semibold text-amber-950">Select an active Store before opening POS.</h2><p className="mt-2 text-sm text-amber-800">Use Platform tenant selection to choose a Store you can access.</p><Link href="/platform" className="mt-5 inline-flex rounded bg-slate-900 px-4 py-2 text-white">Select Store</Link></div></div></PosLayout>;
    }

    return (
        <PosLayout>
            <Head title="Point of Sale" />
            <div className="mx-auto max-w-[1600px] space-y-4 p-3 sm:p-5">
                {completedOrder && <section className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4"><div><strong className="text-emerald-950">Sale completed — {completedOrder.order_number}</strong><p className="text-sm text-emerald-800">Fulfillment: {completedOrder.fulfillment_status.toUpperCase()} · Payment: {completedOrder.payment_status.toUpperCase()} · Total {completedOrder.total_incl_tax} {completedOrder.currency_code}</p></div><div className="flex gap-2"><button type="button" onClick={resetCart} className="rounded bg-slate-900 px-4 py-2 text-white">New Sale</button><Link href={`/sales/orders/${completedOrder.id}`} className="rounded border border-emerald-300 px-4 py-2">View Order</Link></div></section>}
                <section className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-3">
                    <div><span className="text-xs uppercase tracking-wide text-slate-500">Active Store</span><strong className="block">{store.name} · {store.code}</strong></div>
                    <label className="text-xs uppercase tracking-wide text-slate-500">Warehouse<select value={warehouseId ?? ''} onChange={(event) => changeWarehouse(Number(event.target.value))} className="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm normal-case text-slate-950"><option value="">Select Warehouse</option>{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} · {warehouse.code}</option>)}</select></label>
                    <div><span className="text-xs uppercase tracking-wide text-slate-500">Customer</span><strong className="block">{customer?.display_name ?? 'Walk-in customer'}</strong></div>
                </section>
                {warehouses.length === 0 && <p className="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">No active Warehouse is available in this Organization. POS completion is unavailable.</p>}
                <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(26rem,0.85fr)]">
                    <div className="space-y-4"><PosProductSearch warehouseId={warehouseId} onAdd={addProduct} /><PosCustomerSelector selected={customer} canCreate={can.createCustomer} onSelect={setCustomer} />
                        {can.addCustomItem && <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><button type="button" onClick={() => setShowCustom((value) => !value)} className="font-medium">+ Add custom item</button>{showCustom && <form onSubmit={(event) => { event.preventDefault(); addCustomItem(event.currentTarget); }} className="mt-4 grid gap-2 sm:grid-cols-2"><input name="description" required placeholder="Description" className="rounded border px-3 py-2" /><input name="reference" placeholder="Reference (optional)" className="rounded border px-3 py-2" /><input name="unit_label" placeholder="Unit label" className="rounded border px-3 py-2" /><input name="quantity" required defaultValue="1.0000" inputMode="decimal" placeholder="Quantity" className="rounded border px-3 py-2" /><input name="unit_price_excl_tax" required inputMode="decimal" placeholder="Unit price excl. tax" className="rounded border px-3 py-2" /><select name="tax_rate_id" className="rounded border px-3 py-2"><option value="">No tax</option>{taxRates.map((tax) => <option key={tax.id} value={tax.id}>{tax.name} ({tax.rate}%)</option>)}</select><button className="rounded bg-slate-900 px-4 py-2 text-white sm:col-span-2">Add custom item</button></form>}</section>}
                        {recentSales.length > 0 && <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><h2 className="mb-3 font-semibold">Recent POS sales — active Store</h2><div className="space-y-2">{recentSales.map((sale) => <Link key={sale.id} href={`/sales/orders/${sale.id}`} className="flex justify-between gap-3 rounded border border-slate-100 p-2 text-sm hover:bg-slate-50"><span><strong>{sale.order_number}</strong> · {sale.customer_name ?? 'Walk-in'}<br /><span className="text-xs text-slate-500">{new Date(sale.ordered_at).toLocaleString()}</span></span><span className="text-right">{sale.total_incl_tax} {sale.currency_code}<br /><span className="text-xs font-medium text-amber-700">{sale.payment_status.toUpperCase()}</span></span></Link>)}</div></section>}
                    </div>
                    <div className="space-y-3 xl:sticky xl:top-4"><PosCart lines={lines} currencyCode={currencyCode} canOverridePrice={can.overridePrice} canDiscount={can.applyDiscount} onChange={(key, values) => setLines((current) => current.map((line) => line.key === key ? { ...line, ...values } : line))} onRemove={(key) => setLines((current) => current.filter((line) => line.key !== key))} onClear={resetCart} />
                        {errors.map((error, index) => <p key={index} className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>)}
                        <button type="button" disabled={processing || !warehouseId || lines.length === 0} onClick={() => setShowConfirmation(true)} className="w-full rounded-xl bg-emerald-700 px-5 py-4 text-lg font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-40">Complete Sale</button>
                        <p className="text-center text-xs text-slate-500">Completes fulfillment and consumes stock. Payment remains UNPAID.</p>
                    </div>
                </div>
            </div>
            {showConfirmation && <div role="dialog" aria-modal="true" className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4"><div className="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl"><div><h2 className="text-lg font-semibold">Confirm Sale</h2><p className="text-sm text-slate-500">This will reserve and immediately consume Inventory.</p></div><dl className="grid grid-cols-2 gap-2 text-sm"><dt>Store</dt><dd className="text-right font-medium">{store.name}</dd><dt>Warehouse</dt><dd className="text-right font-medium">{selectedWarehouse?.name}</dd><dt>Customer</dt><dd className="text-right font-medium">{customer?.display_name ?? 'Walk-in'}</dd><dt>Items</dt><dd className="text-right font-medium">{lines.length}</dd><dt>Total</dt><dd className="text-right font-semibold">{totals.total.toFixed(4)} {currencyCode}</dd><dt>Payment</dt><dd className="text-right font-semibold text-amber-700">UNPAID</dd></dl><div className="flex justify-end gap-2"><button type="button" onClick={() => setShowConfirmation(false)} className="rounded border px-4 py-2">Back</button><button type="button" disabled={processing} onClick={completeSale} className="rounded bg-emerald-700 px-4 py-2 font-medium text-white disabled:opacity-50">{processing ? 'Completing…' : 'Confirm Sale'}</button></div></div></div>}
        </PosLayout>
    );
}
