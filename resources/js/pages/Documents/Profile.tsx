import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Identifier = { label: string; value: string };
type Profile = {
    legal_name?: string; trade_name?: string; address?: string; phone?: string; email?: string;
    tax_identifier?: string; registration_number?: string; website?: string; additional_identifiers?: Identifier[];
};

export default function DocumentProfile({ organization, profile }: { organization: { id: number; name: string }; profile: Profile }) {
    const form = useForm({
        legal_name: profile.legal_name ?? organization.name,
        trade_name: profile.trade_name ?? '', address: profile.address ?? '', phone: profile.phone ?? '', email: profile.email ?? '',
        tax_identifier: profile.tax_identifier ?? '', registration_number: profile.registration_number ?? '', website: profile.website ?? '',
        additional_identifiers: profile.additional_identifiers ?? [],
    });
    const submit = (event: FormEvent) => { event.preventDefault(); form.put('/document-profile', { preserveScroll: true }); };
    const fields: Array<[keyof typeof form.data, string, string]> = [
        ['legal_name', 'Legal name', 'text'], ['trade_name', 'Trade name', 'text'], ['address', 'Address', 'text'],
        ['phone', 'Phone', 'tel'], ['email', 'Email', 'email'], ['tax_identifier', 'Tax identifier', 'text'],
        ['registration_number', 'Registration number', 'text'], ['website', 'Website', 'url'],
    ];

    return <main className="mx-auto min-h-screen max-w-3xl px-6 py-10">
        <Head title="Document profile" />
        <Link href="/platform" className="text-sm text-slate-600">← Platform</Link>
        <h1 className="mt-4 text-2xl font-semibold">Document seller profile</h1>
        <p className="mt-2 text-sm text-slate-600">Used only for new document snapshots. Existing documents remain unchanged.</p>
        <form onSubmit={submit} className="mt-8 space-y-5 rounded-xl border border-slate-200 p-6">
            <div className="grid gap-5 md:grid-cols-2">{fields.map(([name, label, type]) => <label key={name} className={name === 'address' ? 'md:col-span-2' : ''}>
                <span className="block text-sm font-medium">{label}</span>
                <input type={type} required={name === 'legal_name'} maxLength={name === 'address' ? 2000 : 255} value={form.data[name] as string} onChange={e => form.setData(name, e.target.value as never)} className="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                {form.errors[name] && <span className="mt-1 block text-sm text-red-600">{form.errors[name]}</span>}
            </label>)}</div>
            <fieldset><legend className="text-sm font-medium">Additional identifiers</legend>{form.data.additional_identifiers.map((identifier, index) => <div key={index} className="mt-2 flex gap-2">
                <input aria-label={`Identifier ${index + 1} label`} value={identifier.label} onChange={e => form.setData('additional_identifiers', form.data.additional_identifiers.map((item, i) => i === index ? { ...item, label: e.target.value } : item))} className="w-1/3 rounded border border-slate-300 px-3 py-2" placeholder="Label" />
                <input aria-label={`Identifier ${index + 1} value`} value={identifier.value} onChange={e => form.setData('additional_identifiers', form.data.additional_identifiers.map((item, i) => i === index ? { ...item, value: e.target.value } : item))} className="flex-1 rounded border border-slate-300 px-3 py-2" placeholder="Value" />
                <button type="button" onClick={() => form.setData('additional_identifiers', form.data.additional_identifiers.filter((_, i) => i !== index))} className="rounded border px-3">Remove</button>
            </div>)}{form.data.additional_identifiers.length < 10 && <button type="button" onClick={() => form.setData('additional_identifiers', [...form.data.additional_identifiers, { label: '', value: '' }])} className="mt-2 text-sm text-slate-700">+ Add identifier</button>}</fieldset>
            <button disabled={form.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">{form.processing ? 'Saving…' : 'Save profile'}</button>
        </form>
    </main>;
}
