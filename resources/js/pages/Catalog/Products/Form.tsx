import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import CatalogLayout from '@/layouts/CatalogLayout';

type Option = { id: number; name: string; symbol?: string; rate?: string };
type Product = { id: number; name: string; description: string | null; brand_id: number | null; default_category_id: number | null; default_unit_id: number | null; status: string };
type Props = { product: Product | null; brands: Option[]; categories: Option[]; units: Option[]; taxRates: Option[] };

export default function ProductForm({ product, brands, categories, units, taxRates }: Props) {
    const form = useForm({
        name: product?.name ?? '', description: product?.description ?? '', brand_id: product?.brand_id ?? null,
        category_id: product?.default_category_id ?? null, unit_id: product?.default_unit_id ?? null, status: product?.status ?? 'active',
        variant: { label: '', sku: '', reference: '', barcode: '', purchase_price: '', default_sale_price: '', tax_rate_id: null as number | null, status: 'active' },
    });
    const creating = product === null;

    function submit(event: FormEvent) {
        event.preventDefault();
        creating ? form.post('/catalog/products') : form.patch(`/catalog/products/${product.id}`);
    }
    const selectId = (value: string) => value ? Number(value) : null;

    return <CatalogLayout>
        <Head title={creating ? 'Create Product' : `Edit ${product.name}`} />
        <form onSubmit={submit} className="mx-auto max-w-3xl space-y-6">
            <h2 className="text-xl font-semibold">{creating ? 'Create Product' : 'Edit Product'}</h2>
            <div className="grid gap-4 rounded-lg border p-5 md:grid-cols-2">
                <label className="md:col-span-2">Name<input className="mt-1 w-full rounded border px-3 py-2" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></label>
                <label>Brand<select className="mt-1 w-full rounded border px-3 py-2" value={form.data.brand_id ?? ''} onChange={(e) => form.setData('brand_id', selectId(e.target.value))}><option value="">None</option>{brands.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Category<select className="mt-1 w-full rounded border px-3 py-2" value={form.data.category_id ?? ''} onChange={(e) => form.setData('category_id', selectId(e.target.value))}><option value="">None</option>{categories.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Unit<select className="mt-1 w-full rounded border px-3 py-2" value={form.data.unit_id ?? ''} onChange={(e) => form.setData('unit_id', selectId(e.target.value))}><option value="">None</option>{units.map(x => <option key={x.id} value={x.id}>{x.name} ({x.symbol})</option>)}</select></label>
                <label>Status<select className="mt-1 w-full rounded border px-3 py-2" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <label className="md:col-span-2">Description<textarea className="mt-1 w-full rounded border px-3 py-2" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={4} /></label>
            </div>
            {creating && <div className="grid gap-4 rounded-lg border p-5 md:grid-cols-2">
                <h3 className="md:col-span-2 font-semibold">Initial variant</h3>
                {(['sku', 'reference', 'barcode', 'purchase_price', 'default_sale_price'] as const).map((field) => <label key={field} className={field === 'sku' ? 'md:col-span-2' : ''}>{field.replaceAll('_', ' ')}<input className="mt-1 w-full rounded border px-3 py-2" value={form.data.variant[field]} onChange={(e) => form.setData('variant', { ...form.data.variant, [field]: e.target.value })} required={field === 'sku' || field === 'default_sale_price'} /></label>)}
                <label>Tax rate<select className="mt-1 w-full rounded border px-3 py-2" value={form.data.variant.tax_rate_id ?? ''} onChange={(e) => form.setData('variant', { ...form.data.variant, tax_rate_id: selectId(e.target.value) })}><option value="">None</option>{taxRates.map(x => <option key={x.id} value={x.id}>{x.name} ({x.rate}%)</option>)}</select></label>
            </div>}
            {Object.keys(form.errors).length > 0 && <p className="text-sm text-red-600">Please correct the highlighted catalog data.</p>}
            <button disabled={form.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">Save</button>
        </form>
    </CatalogLayout>;
}
