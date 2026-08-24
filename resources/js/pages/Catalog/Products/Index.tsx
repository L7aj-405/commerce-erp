import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import CatalogLayout from '@/layouts/CatalogLayout';

type Option = { id: number; name: string };
type Variant = { id: number; sku: string; reference: string | null; barcode: string | null; default_sale_price: string };
type Product = { id: number; name: string; status: string; brand: Option | null; default_category: Option | null; variants: Variant[] };
type LinkData = { url: string | null; label: string; active: boolean };

type Props = {
    products: { data: Product[]; links: LinkData[]; from: number | null; to: number | null; total: number };
    filters: { search?: string; status?: string; brand?: number; category?: number };
    brands: Option[];
    categories: Option[];
};

export default function ProductIndex({ products, filters, brands, categories }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get('/catalog/products', { ...filters, search }, { preserveState: true, replace: true });
    }

    return (
        <CatalogLayout>
            <Head title="Products" />
            <div className="mb-5 flex items-center justify-between gap-4">
                <h2 className="text-xl font-semibold">Products</h2>
                <Link href="/catalog/products/create" className="rounded bg-slate-900 px-4 py-2 text-white">Create product</Link>
            </div>

            <form onSubmit={apply} className="mb-6 grid gap-3 rounded-lg bg-slate-50 p-4 md:grid-cols-4">
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, SKU, reference, barcode, brand" className="rounded border px-3 py-2" />
                <select value={filters.status ?? ''} onChange={(e) => router.get('/catalog/products', { ...filters, status: e.target.value || undefined }, { preserveState: true })} className="rounded border px-3 py-2">
                    <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
                </select>
                <select value={filters.brand ?? ''} onChange={(e) => router.get('/catalog/products', { ...filters, brand: e.target.value || undefined }, { preserveState: true })} className="rounded border px-3 py-2">
                    <option value="">All brands</option>{brands.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                </select>
                <select value={filters.category ?? ''} onChange={(e) => router.get('/catalog/products', { ...filters, category: e.target.value || undefined }, { preserveState: true })} className="rounded border px-3 py-2">
                    <option value="">All categories</option>{categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                </select>
            </form>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50"><tr><th className="p-3">Product</th><th className="p-3">Brand</th><th className="p-3">Category</th><th className="p-3">SKU</th><th className="p-3">Status</th></tr></thead>
                    <tbody>{products.data.map((product) => (
                        <tr key={product.id} className="border-t">
                            <td className="p-3"><Link href={`/catalog/products/${product.id}`} className="font-medium hover:underline">{product.name}</Link></td>
                            <td className="p-3">{product.brand?.name ?? '—'}</td><td className="p-3">{product.default_category?.name ?? '—'}</td>
                            <td className="p-3">{product.variants[0]?.sku ?? '—'}</td><td className="p-3 capitalize">{product.status}</td>
                        </tr>
                    ))}</tbody>
                </table>
            </div>
            <div className="mt-5 flex flex-wrap gap-2">{products.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveScroll className={`rounded border px-3 py-1 ${link.active ? 'bg-slate-900 text-white' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div>
        </CatalogLayout>
    );
}
