import type { CartLine } from './types';

type Props = {
    lines: CartLine[];
    currencyCode: string;
    canOverridePrice: boolean;
    canDiscount: boolean;
    onChange: (key: string, values: Partial<CartLine>) => void;
    onRemove: (key: string) => void;
    onClear: () => void;
};

export function calculateLine(line: CartLine) {
    const quantity = Number(line.quantity) || 0;
    const price = Number(line.unit_price_excl_tax) || 0;
    const subtotal = quantity * price;
    const discountValue = Number(line.discount_value) || 0;
    const discount = line.discount_type === 'percentage' ? subtotal * discountValue / 100 : line.discount_type === 'fixed' ? discountValue : 0;
    const taxable = Math.max(0, subtotal - discount);
    const tax = taxable * (Number(line.tax_rate) || 0) / 100;
    return { subtotal, discount, tax, total: taxable + tax };
}

export default function PosCart({ lines, currencyCode, canOverridePrice, canDiscount, onChange, onRemove, onClear }: Props) {
    const totals = lines.reduce((sum, line) => {
        const value = calculateLine(line);
        return { subtotal: sum.subtotal + value.subtotal, discount: sum.discount + value.discount, tax: sum.tax + value.tax, total: sum.total + value.total };
    }, { subtotal: 0, discount: 0, tax: 0, total: 0 });

    return (
        <section className="flex min-h-[30rem] flex-col rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-200 p-4"><div><h2 className="font-semibold">Cart</h2><p className="text-xs text-slate-500">{lines.length} line{lines.length === 1 ? '' : 's'}</p></div>{lines.length > 0 && <button type="button" onClick={onClear} className="text-sm text-red-700 underline">Clear cart</button>}</div>
            <div className="flex-1 divide-y divide-slate-100 overflow-y-auto">
                {lines.length === 0 && <p className="p-8 text-center text-sm text-slate-500">Scan or select a product to begin.</p>}
                {lines.map((line) => {
                    const preview = calculateLine(line);
                    const insufficient = line.available !== undefined && Number(line.quantity) > Number(line.available);
                    return <article key={line.key} className="space-y-3 p-4">
                        <div className="flex justify-between gap-3"><div className="min-w-0"><strong className="block truncate">{line.description}</strong><span className="text-xs text-slate-500">{line.variant_name ?? line.line_type}{line.sku ? ` · ${line.sku}` : ''}</span></div><button type="button" onClick={() => onRemove(line.key)} className="text-sm text-red-700">Remove</button></div>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <label className="text-xs text-slate-600">Quantity<input inputMode="decimal" value={line.quantity} onChange={(event) => onChange(line.key, { quantity: event.target.value })} className="mt-1 w-full rounded border px-2 py-1.5 text-sm" /></label>
                            <label className="text-xs text-slate-600">Unit price<input readOnly={line.line_type === 'catalog' && !canOverridePrice} inputMode="decimal" value={line.unit_price_excl_tax} onChange={(event) => onChange(line.key, { unit_price_excl_tax: event.target.value })} className="mt-1 w-full rounded border px-2 py-1.5 text-sm read-only:bg-slate-100" /></label>
                            <label className="text-xs text-slate-600">Discount<select disabled={!canDiscount} value={line.discount_type} onChange={(event) => onChange(line.key, { discount_type: event.target.value as CartLine['discount_type'], discount_value: '0' })} className="mt-1 w-full rounded border px-2 py-1.5 text-sm disabled:bg-slate-100"><option value="none">None</option><option value="fixed">Fixed</option><option value="percentage">Percent</option></select></label>
                            <label className="text-xs text-slate-600">Value<input disabled={!canDiscount || line.discount_type === 'none'} inputMode="decimal" value={line.discount_value} onChange={(event) => onChange(line.key, { discount_value: event.target.value })} className="mt-1 w-full rounded border px-2 py-1.5 text-sm disabled:bg-slate-100" /></label>
                        </div>
                        <div className="flex justify-between text-sm"><span className={insufficient ? 'font-medium text-red-700' : 'text-slate-500'}>{line.available !== undefined ? `Available ${line.available}` : 'Non-inventory item'}{insufficient ? ' · insufficient' : ''}</span><strong>{preview.total.toFixed(4)} {currencyCode}</strong></div>
                    </article>;
                })}
            </div>
            <div className="grid grid-cols-2 gap-3 border-t border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-4">
                <span>Subtotal<br /><strong>{totals.subtotal.toFixed(4)}</strong></span><span>Discount<br /><strong>{totals.discount.toFixed(4)}</strong></span><span>Tax<br /><strong>{totals.tax.toFixed(4)}</strong></span><span>Total<br /><strong>{totals.total.toFixed(4)} {currencyCode}</strong></span>
            </div>
        </section>
    );
}
