import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import { Button } from '@/components/ui/Button';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';

type Supplier = {
    id: number;
    name: string;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    notes: string | null;
    active: boolean;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    suppliers: { data: Supplier[]; links: LinkData[] };
    filters: { search?: string; active?: string };
    can: { manage: boolean };
};

const blank = { name: '', contact_person: '', phone: '', email: '', address: '', notes: '', active: true };

export default function SuppliersIndex({ suppliers, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [editing, setEditing] = useState<Supplier | null>(null);
    const [creating, setCreating] = useState(false);
    const form = useForm<typeof blank>({ ...blank });

    const visit = (next: Record<string, string | undefined>) =>
        router.get('/procurement/suppliers', next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search: search || undefined, active: filters.active, page: undefined }), 280);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const startCreate = () => {
        form.setData({ ...blank });
        form.clearErrors();
        setEditing(null);
        setCreating(true);
    };
    const startEdit = (supplier: Supplier) => {
        form.setData({
            name: supplier.name,
            contact_person: supplier.contact_person ?? '',
            phone: supplier.phone ?? '',
            email: supplier.email ?? '',
            address: supplier.address ?? '',
            notes: supplier.notes ?? '',
            active: supplier.active,
        });
        form.clearErrors();
        setCreating(false);
        setEditing(supplier);
    };
    const close = () => {
        setCreating(false);
        setEditing(null);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editing) {
            form.patch(`/procurement/suppliers/${editing.id}`, { preserveScroll: true, onSuccess: close });
        } else {
            form.post('/procurement/suppliers', { preserveScroll: true, onSuccess: close });
        }
    };

    const showForm = creating || editing !== null;

    return (
        <ProcurementLayout>
            <Head title="Fournisseurs" />
            <PageHeader
                title="Fournisseurs"
                description="Répertoire minimal des fournisseurs pour les commandes spéciales. Ce n’est pas de la comptabilité fournisseurs."
                actions={
                    <>
                        <Link
                            href="/procurement"
                            className="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm text-slate-900 hover:bg-slate-50"
                        >
                            Approvisionnements
                        </Link>
                        {can.manage && !showForm && <Button onClick={startCreate}>Nouveau fournisseur</Button>}
                    </>
                }
            />

            {showForm && (
                <form onSubmit={submit} className="mb-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-900">
                        {editing ? `Modifier ${editing.name}` : 'Nouveau fournisseur'}
                    </h2>
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Nom" error={form.errors.name}>
                            <input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                required
                            />
                        </Field>
                        <Field label="Personne de contact" error={form.errors.contact_person}>
                            <input
                                value={form.data.contact_person}
                                onChange={(e) => form.setData('contact_person', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </Field>
                        <Field label="Téléphone" error={form.errors.phone}>
                            <input
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </Field>
                        <Field label="Email" error={form.errors.email}>
                            <input
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </Field>
                        <Field label="Adresse" error={form.errors.address}>
                            <textarea
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                                rows={2}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </Field>
                        <Field label="Notes" error={form.errors.notes}>
                            <textarea
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                rows={2}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </Field>
                    </div>
                    <label className="mt-3 flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.data.active}
                            onChange={(e) => form.setData('active', e.target.checked)}
                        />
                        Fournisseur actif
                    </label>
                    <div className="mt-4 flex gap-2">
                        <Button type="submit" loading={form.processing} loadingText="Enregistrement…">
                            Enregistrer
                        </Button>
                        <button type="button" onClick={close} className="rounded-lg px-4 py-2 text-sm text-slate-600">
                            Annuler
                        </button>
                    </div>
                </form>
            )}

            <div className="mb-4">
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Nom, contact, téléphone" />
            </div>

            {suppliers.data.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
                    Aucun fournisseur.
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white md:block">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Nom</th>
                                    <th className="px-4 py-3 font-medium">Contact</th>
                                    <th className="px-4 py-3 font-medium">Téléphone</th>
                                    <th className="px-4 py-3 font-medium">Email</th>
                                    <th className="px-4 py-3 font-medium">Statut</th>
                                    {can.manage && <th className="px-4 py-3" />}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {suppliers.data.map((supplier) => (
                                    <tr key={supplier.id}>
                                        <td className="px-4 py-3 font-medium text-slate-900">{supplier.name}</td>
                                        <td className="px-4 py-3 text-slate-600">{supplier.contact_person ?? '—'}</td>
                                        <td className="px-4 py-3 text-slate-600">{supplier.phone ?? '—'}</td>
                                        <td className="px-4 py-3 text-slate-600">{supplier.email ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                                    supplier.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                                                }`}
                                            >
                                                {supplier.active ? 'Actif' : 'Inactif'}
                                            </span>
                                        </td>
                                        {can.manage && (
                                            <td className="px-4 py-3 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => startEdit(supplier)}
                                                    className="text-sm text-slate-900 underline"
                                                >
                                                    Modifier
                                                </button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {suppliers.data.map((supplier) => (
                            <li key={supplier.id} className="rounded-xl border border-slate-200 bg-white p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-slate-900">{supplier.name}</p>
                                        {supplier.contact_person && <p className="truncate text-[13px] text-slate-500">{supplier.contact_person}</p>}
                                    </div>
                                    <span
                                        className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${
                                            supplier.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                                        }`}
                                    >
                                        {supplier.active ? 'Actif' : 'Inactif'}
                                    </span>
                                </div>
                                <dl className="mt-3 space-y-1 text-[13px]">
                                    <div className="flex gap-1.5">
                                        <dt className="shrink-0 text-slate-400">Tél.</dt>
                                        <dd className="min-w-0 truncate text-slate-600">
                                            {supplier.phone ? <a href={`tel:${supplier.phone}`} className="text-slate-900">{supplier.phone}</a> : '—'}
                                        </dd>
                                    </div>
                                    <div className="flex gap-1.5">
                                        <dt className="shrink-0 text-slate-400">Email</dt>
                                        <dd className="min-w-0 truncate text-slate-600">
                                            {supplier.email ? <a href={`mailto:${supplier.email}`} className="text-slate-900">{supplier.email}</a> : '—'}
                                        </dd>
                                    </div>
                                </dl>
                                {can.manage && (
                                    <button
                                        type="button"
                                        onClick={() => startEdit(supplier)}
                                        className="mt-3 inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-300 px-3 text-[13px] font-medium text-slate-900 hover:bg-slate-50"
                                    >
                                        Modifier
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={suppliers.links} />
        </ProcurementLayout>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <label className="block text-sm">
            <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">{label}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-rose-600">{error}</span>}
        </label>
    );
}
