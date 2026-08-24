import { Head, Link, router } from '@inertiajs/react';
import CatalogLayout from '@/layouts/CatalogLayout';

type Variant = { id: number; label: string | null; sku: string; reference: string | null; barcode: string | null; purchase_price: string | null; default_sale_price: string; status: string; tax_rate: { name: string; rate: string } | null };
type Product = { id: number; name: string; description: string | null; status: string; brand: { name: string } | null; default_category: { name: string } | null; default_unit: { name: string; symbol: string } | null; variants: Variant[] };

export default function ProductShow({ product }: { product: Product }) {
    return <CatalogLayout>
        <Head title={product.name} />
        <div className="mb-6 flex items-center justify-between"><div><h2 className="text-2xl font-semibold">{product.name}</h2><p className="capitalize text-slate-500">{product.status}</p></div><div className="flex gap-2"><Link href={`/catalog/products/${product.id}/edit`} className="rounded border px-4 py-2">Edit</Link><button onClick={() => router.patch(`/catalog/products/${product.id}/archive`)} className="rounded border border-red-300 px-4 py-2 text-red-700">Archive</button></div></div>
        <dl className="grid gap-4 rounded-lg border p-5 md:grid-cols-3"><div><dt className="text-sm text-slate-500">Brand</dt><dd>{product.brand?.name ?? '—'}</dd></div><div><dt className="text-sm text-slate-500">Category</dt><dd>{product.default_category?.name ?? '—'}</dd></div><div><dt className="text-sm text-slate-500">Unit</dt><dd>{product.default_unit ? `${product.default_unit.name} (${product.default_unit.symbol})` : '—'}</dd></div><div className="md:col-span-3"><dt className="text-sm text-slate-500">Description</dt><dd>{product.description ?? '—'}</dd></div></dl>
        <h3 className="mb-3 mt-8 text-lg font-semibold">Variants</h3><div className="overflow-x-auto rounded-lg border"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr><th className="p-3">SKU</th><th className="p-3">Reference</th><th className="p-3">Barcode</th><th className="p-3">Sale price</th><th className="p-3">Tax</th><th className="p-3">Status</th></tr></thead><tbody>{product.variants.map(v => <tr key={v.id} className="border-t"><td className="p-3">{v.sku}</td><td className="p-3">{v.reference ?? '—'}</td><td className="p-3">{v.barcode ?? '—'}</td><td className="p-3">{v.default_sale_price}</td><td className="p-3">{v.tax_rate?.name ?? '—'}</td><td className="p-3 capitalize">{v.status}</td></tr>)}</tbody></table></div>
    </CatalogLayout>;
}
