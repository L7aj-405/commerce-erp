import ProductImage from '@/components/catalog/ProductImage';
import { formatMoney, formatQuantity } from '@/utils/format';
import type { ProductResult } from './types';

export default function PosProductCard({ product, onAdd }: { product: ProductResult; onAdd: (product: ProductResult) => void }) {
    const available = Number(product.stock.available);
    const lowStock = available > 0 && available <= 1;

    return (
        <button
            type="button"
            onClick={() => available > 0 && onAdd(product)}
            disabled={available <= 0}
            className="group flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-3 text-left shadow-sm transition hover:border-slate-300 hover:shadow disabled:cursor-not-allowed disabled:opacity-70"
        >
            <div className="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl bg-slate-50">
                <ProductImage name={product.product_name} imageUrl={product.image_url} className="h-full w-full p-2" roundedClassName="rounded-none" />
            </div>
            <div className="mt-3 flex flex-1 flex-col">
                <p className="line-clamp-2 font-semibold text-slate-900">{product.product_name}</p>
                <p className="mt-1 text-xs text-slate-500">{product.brand?.name ?? 'Sans marque'}</p>
                <div className="mt-2 space-y-1 text-xs text-slate-500">
                    {product.sku && <p>SKU: {product.sku}</p>}
                    {product.reference && <p>Réf: {product.reference}</p>}
                </div>
                <div className="mt-3 space-y-1">
                    <p className="text-base font-semibold text-slate-950">{formatMoney(product.default_sale_price)}</p>
                    <p className={`text-xs ${available > 0 ? 'text-emerald-700' : 'text-red-700'}`}>
                        {available > 0 ? `Disponible ici: ${formatQuantity(product.stock.available)}` : 'Rupture ici'}
                    </p>
                    {Number(product.stock.total_available) > available && (
                        <p className="text-xs text-slate-500">Stock total: {formatQuantity(product.stock.total_available)}</p>
                    )}
                    {lowStock && <p className="text-xs text-amber-700">Plus que 1 disponible</p>}
                </div>
            </div>
            <span className="mt-4 inline-flex min-h-10 items-center justify-center rounded-xl bg-slate-950 px-3 py-2 text-sm font-medium text-white group-disabled:bg-slate-200 group-disabled:text-slate-500">
                {available > 0 ? 'Ajouter' : 'Indisponible'}
            </span>
        </button>
    );
}
