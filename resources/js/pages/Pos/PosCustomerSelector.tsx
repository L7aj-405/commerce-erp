import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import type { Customer } from './types';

type Props = {
    selected: Customer | null;
    canCreate: boolean;
    onSelect: (customer: Customer | null) => void;
};

export default function PosCustomerSelector({ selected, canCreate, onSelect }: Props) {
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<Customer[]>([]);
    const [error, setError] = useState('');
    const [showCreate, setShowCreate] = useState(false);
    const create = useForm({ display_name: '', company_name: '', phone: '', email: '' });

    async function lookup(event: FormEvent) {
        event.preventDefault();
        if (!search.trim()) return;
        setError('');
        const response = await fetch(`/pos/customers?${new URLSearchParams({ search: search.trim() })}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
            setError('Customer search failed.');
            return;
        }
        const payload = await response.json() as { data: Customer[] };
        setResults(payload.data);
    }

    return (
        <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between gap-3">
                <div><h2 className="font-semibold">Customer</h2><p className="text-xs text-slate-500">Optional — leave empty for walk-in.</p></div>
                {selected && <button type="button" onClick={() => onSelect(null)} className="text-sm text-slate-600 underline">Use walk-in</button>}
            </div>
            {selected ? <div className="rounded-lg bg-emerald-50 p-3 text-sm"><strong>{selected.display_name}</strong><br /><span className="text-emerald-800">{selected.phone ?? selected.email ?? selected.company_name ?? 'Selected customer'}</span></div> : (
                <>
                    <form onSubmit={lookup} className="flex gap-2"><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, company, phone or email" className="min-w-0 flex-1 rounded border border-slate-300 px-3 py-2" /><button className="rounded border border-slate-300 px-3">Search</button></form>
                    {error && <p className="text-sm text-red-700">{error}</p>}
                    <div className="max-h-40 space-y-1 overflow-y-auto">{results.map((customer) => <button key={customer.id} type="button" onClick={() => onSelect(customer)} className="block w-full rounded p-2 text-left text-sm hover:bg-slate-100"><strong>{customer.display_name}</strong>{customer.company_name ? ` · ${customer.company_name}` : ''}<br /><span className="text-xs text-slate-500">{customer.phone ?? customer.email}</span></button>)}</div>
                </>
            )}
            {canCreate && !selected && <button type="button" onClick={() => setShowCreate((value) => !value)} className="text-sm font-medium text-slate-700 underline">+ Quick customer</button>}
            {showCreate && <form onSubmit={(event) => { event.preventDefault(); create.post('/pos/customers', { preserveScroll: true, onSuccess: () => { create.reset(); setShowCreate(false); } }); }} className="grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-2">
                <input required value={create.data.display_name} onChange={(event) => create.setData('display_name', event.target.value)} placeholder="Name" className="rounded border px-3 py-2" />
                <input required value={create.data.phone} onChange={(event) => create.setData('phone', event.target.value)} placeholder="Phone" className="rounded border px-3 py-2" />
                <input value={create.data.email} onChange={(event) => create.setData('email', event.target.value)} placeholder="Email (optional)" className="rounded border px-3 py-2" />
                <input value={create.data.company_name} onChange={(event) => create.setData('company_name', event.target.value)} placeholder="Company (optional)" className="rounded border px-3 py-2" />
                {Object.values(create.errors).map((message, index) => <p key={index} className="text-sm text-red-700 sm:col-span-2">{message}</p>)}
                <button disabled={create.processing} className="rounded bg-slate-900 px-3 py-2 text-sm text-white sm:col-span-2">Create and select customer</button>
            </form>}
        </section>
    );
}
