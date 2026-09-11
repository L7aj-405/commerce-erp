import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import CatalogLayout from '@/layouts/CatalogLayout';
import { Spinner } from '@/components/ui/Spinner';

type RecordItem = { id: number; name: string; slug?: string; symbol?: string; rate?: string; is_default?: boolean; parent_id?: number | null; status: string; parent?: { name: string } | null };
type StoreRow = { id: number; name: string; code: string; default_tax_rate_id: number | null };
type Props = { kind: 'brands' | 'categories' | 'units' | 'tax-rates'; title: string; records: RecordItem[]; parents?: { id: number; name: string }[]; stores?: StoreRow[] };

export default function ReferenceData({ kind, title, records, parents = [], stores = [] }: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const form = useForm({ name: '', slug: '', symbol: '', rate: '', is_default: false, parent_id: null as number | null, status: 'active' });

    function select(record: RecordItem) {
        setEditing(record.id);
        form.setData({ name: record.name, slug: record.slug ?? '', symbol: record.symbol ?? '', rate: record.rate ?? '', is_default: record.is_default ?? false, parent_id: record.parent_id ?? null, status: record.status });
    }
    function reset() {
        setEditing(null);
        form.reset();
    }
    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: reset };
        editing ? form.patch(`/catalog/${kind}/${editing}`, options) : form.post(`/catalog/${kind}`, options);
    }

    return <CatalogLayout>
        <Head title={title} />
        <div className="grid gap-8 lg:grid-cols-[1fr_22rem]">
            <section><h2 className="mb-4 text-xl font-semibold">{title}</h2><div className="overflow-hidden rounded-lg border"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr><th className="p-3">Name</th><th className="p-3">Details</th><th className="p-3">Status</th><th /></tr></thead><tbody>{records.map(record => <tr key={record.id} className="border-t"><td className="p-3 font-medium">{record.name}</td><td className="p-3 text-slate-600">{record.parent?.name ?? record.symbol ?? (record.rate !== undefined ? `${record.rate}%` : record.slug)}{record.is_default && <span className="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Par défaut</span>}</td><td className="p-3 capitalize">{record.status}</td><td className="p-3 text-right"><button onClick={() => select(record)} className="underline">Edit</button></td></tr>)}</tbody></table></div></section>
            <form onSubmit={submit} className="h-fit space-y-4 rounded-lg border p-5"><h3 className="font-semibold">{editing ? 'Edit' : 'Create'} {title.replace(/s$/, '')}</h3>
                <label className="block text-sm">Name<input className="mt-1 w-full rounded border px-3 py-2" value={form.data.name} onChange={e => form.setData('name', e.target.value)} required /></label>
                {(kind === 'brands' || kind === 'categories') && <label className="block text-sm">Slug<input className="mt-1 w-full rounded border px-3 py-2" value={form.data.slug} onChange={e => form.setData('slug', e.target.value)} required /></label>}
                {kind === 'categories' && <label className="block text-sm">Parent<select className="mt-1 w-full rounded border px-3 py-2" value={form.data.parent_id ?? ''} onChange={e => form.setData('parent_id', e.target.value ? Number(e.target.value) : null)}><option value="">None</option>{parents.filter(x => x.id !== editing).map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></label>}
                {kind === 'units' && <label className="block text-sm">Symbol<input className="mt-1 w-full rounded border px-3 py-2" value={form.data.symbol} onChange={e => form.setData('symbol', e.target.value)} required /></label>}
                {kind === 'tax-rates' && <label className="block text-sm">Rate (%)<input type="number" min="0" max="100" step="0.0001" className="mt-1 w-full rounded border px-3 py-2" value={form.data.rate} onChange={e => form.setData('rate', e.target.value)} required /></label>}
                {kind === 'tax-rates' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_default} onChange={e => form.setData('is_default', e.target.checked)} className="size-4 rounded border-slate-300" />Taxe par défaut des nouveaux produits</label>}
                <label className="block text-sm">Status<select className="mt-1 w-full rounded border px-3 py-2" value={form.data.status} onChange={e => form.setData('status', e.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                {Object.keys(form.errors).length > 0 && <p className="text-sm text-red-600">Please correct the submitted data.</p>}
                <div className="flex gap-2"><button disabled={form.processing} aria-busy={form.processing || undefined} className="inline-flex items-center gap-2 rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-60">{form.processing && <Spinner size="sm" />}{form.processing ? 'Enregistrement…' : 'Save'}</button>{editing && <button type="button" onClick={reset} className="rounded border px-4 py-2">Cancel</button>}</div>
            </form>
        </div>

        {kind === 'tax-rates' && stores.length > 0 && (
            <section className="mt-10 max-w-2xl">
                <h2 className="text-xl font-semibold">Taxe par défaut du magasin</h2>
                <p className="mt-1 text-sm text-slate-600">
                    Appliquée automatiquement aux produits qui n’ont pas leur propre taxe. Sert notamment à calculer le prix HT
                    des produits importés en TTC uniquement. À défaut, la taxe par défaut de l’organisation est utilisée.
                </p>
                <div className="mt-4 divide-y rounded-lg border">
                    {stores.map(store => (
                        <div key={store.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div>
                                <p className="font-medium">{store.name}</p>
                                <p className="text-xs text-slate-500">{store.code}</p>
                            </div>
                            <select
                                value={store.default_tax_rate_id ?? ''}
                                onChange={e => router.patch(`/stores/${store.id}`, {
                                    name: store.name,
                                    code: store.code,
                                    default_tax_rate_id: e.target.value ? Number(e.target.value) : null,
                                }, { preserveScroll: true })}
                                className="min-w-56 rounded border px-3 py-2 text-sm"
                            >
                                <option value="">Aucune (utiliser la taxe de l’organisation)</option>
                                {records.filter(rate => rate.status === 'active').map(rate => (
                                    <option key={rate.id} value={rate.id}>{rate.name} ({rate.rate}%)</option>
                                ))}
                            </select>
                        </div>
                    ))}
                </div>
            </section>
        )}
    </CatalogLayout>;
}
