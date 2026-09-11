import { Button } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

type CustomerResult = {
    id: number;
    display_name: string;
    company_name: string | null;
    phone: string | null;
    email: string | null;
    tax_identifier: string | null;
    billing_address: string | null;
};
type Props = {
    defaults: { default_validity_days: number; default_terms: string | null; default_notes: string | null };
    currencyCode: string;
    can: { createCustomer: boolean };
};

function addDays(days: number): string {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
}

export default function QuotationCreate({ defaults, currencyCode, can }: Props) {
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({
        customer_id: null as number | null,
        currency_code: currencyCode,
        quotation_date: today,
        valid_until: addDays(defaults.default_validity_days),
        customer_name: '',
        customer_company: '',
        customer_email: '',
        customer_phone: '',
        customer_tax_identifier: '',
        billing_address: '',
        notes: defaults.default_notes ?? '',
        terms: defaults.default_terms ?? '',
    });

    const [query, setQuery] = useState('');
    const [results, setResults] = useState<CustomerResult[]>([]);
    const [picked, setPicked] = useState<CustomerResult | null>(null);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => {
        window.clearTimeout(timer.current);
        if (query.trim() === '') {
            setResults([]);
            return;
        }
        timer.current = window.setTimeout(async () => {
            try {
                const res = await fetch(`/quotations/customer-search?search=${encodeURIComponent(query.trim())}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) setResults(((await res.json()) as { data: CustomerResult[] }).data);
            } catch {
                /* offline */
            }
        }, 300);
        return () => window.clearTimeout(timer.current);
    }, [query]);

    const choose = (c: CustomerResult) => {
        setPicked(c);
        setResults([]);
        setQuery('');
        form.setData({
            ...form.data,
            customer_id: c.id,
            customer_name: c.display_name,
            customer_company: c.company_name ?? '',
            customer_email: c.email ?? '',
            customer_phone: c.phone ?? '',
            customer_tax_identifier: c.tax_identifier ?? '',
            billing_address: c.billing_address ?? '',
        });
    };

    const clearCustomer = () => {
        setPicked(null);
        form.setData('customer_id', null);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/quotations');
    };

    return (
        <SalesLayout>
            <Head title="Nouveau devis" />
            <div className="mx-auto max-w-3xl">
                <Link href="/quotations" className="text-sm text-ink-muted">
                    ← Devis
                </Link>
                <PageHeader title="Nouveau devis" description="Le devis s’ouvre ensuite en édition : ajoutez les articles ligne par ligne." />

                <form onSubmit={submit} className="space-y-6">
                    <section className="rounded-card border border-line bg-surface p-5">
                        <h2 className="mb-3 text-sm font-semibold text-ink">Client</h2>
                        {picked ? (
                            <div className="flex items-center justify-between rounded-field border border-line bg-raised px-3 py-2 text-sm">
                                <span>
                                    <span className="font-medium text-ink">{picked.company_name || picked.display_name}</span>
                                    {picked.company_name && <span className="text-ink-muted"> · {picked.display_name}</span>}
                                </span>
                                <button type="button" onClick={clearCustomer} className="text-xs text-ink-muted hover:underline">
                                    Changer
                                </button>
                            </div>
                        ) : (
                            <div className="relative">
                                <input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Rechercher un client (nom, société, téléphone…)"
                                    className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                                />
                                {results.length > 0 && (
                                    <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-field border border-line-strong bg-surface text-sm shadow-lg">
                                        {results.map((c) => (
                                            <li key={c.id}>
                                                <button type="button" onClick={() => choose(c)} className="block w-full px-3 py-2 text-left hover:bg-raised">
                                                    <span className="font-medium text-ink">{c.company_name || c.display_name}</span>
                                                    {c.phone && <span className="block text-xs text-ink-faint">{c.phone}</span>}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <p className="mt-1 text-xs text-ink-faint">
                                    Ou laissez vide et saisissez un client libre ci-dessous.{' '}
                                    {can.createCustomer && (
                                        <Link href="/sales/customers/create" className="underline">
                                            Créer une fiche client
                                        </Link>
                                    )}
                                </p>
                            </div>
                        )}

                        <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <Field label="Nom / raison sociale">
                                <input value={form.data.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} className={input} />
                            </Field>
                            <Field label="Société">
                                <input value={form.data.customer_company} onChange={(e) => form.setData('customer_company', e.target.value)} className={input} />
                            </Field>
                            <Field label="Téléphone">
                                <input value={form.data.customer_phone} onChange={(e) => form.setData('customer_phone', e.target.value)} className={input} />
                            </Field>
                            <Field label="Email">
                                <input type="email" value={form.data.customer_email} onChange={(e) => form.setData('customer_email', e.target.value)} className={input} />
                            </Field>
                            <Field label="ICE">
                                <input value={form.data.customer_tax_identifier} onChange={(e) => form.setData('customer_tax_identifier', e.target.value)} className={input} />
                            </Field>
                            <Field label="Adresse de facturation">
                                <input value={form.data.billing_address} onChange={(e) => form.setData('billing_address', e.target.value)} className={input} />
                            </Field>
                        </div>
                    </section>

                    <section className="rounded-card border border-line bg-surface p-5">
                        <h2 className="mb-3 text-sm font-semibold text-ink">Dates</h2>
                        <div className="grid grid-cols-2 gap-3 text-sm">
                            <Field label="Date du devis">
                                <input type="date" value={form.data.quotation_date} onChange={(e) => form.setData('quotation_date', e.target.value)} className={input} />
                            </Field>
                            <Field label="Valable jusqu’au">
                                <input type="date" value={form.data.valid_until} onChange={(e) => form.setData('valid_until', e.target.value)} className={input} />
                            </Field>
                        </div>
                    </section>

                    <section className="rounded-card border border-line bg-surface p-5">
                        <h2 className="mb-3 text-sm font-semibold text-ink">Notes / Conditions</h2>
                        <div className="space-y-3 text-sm">
                            <Field label="Notes">
                                <textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={input} rows={2} />
                            </Field>
                            <Field label="Conditions">
                                <textarea value={form.data.terms} onChange={(e) => form.setData('terms', e.target.value)} className={input} rows={2} />
                            </Field>
                        </div>
                    </section>

                    {Object.values(form.errors).map((err) => err && <p key={err} className="text-sm text-danger">{err}</p>)}

                    <div className="flex gap-2">
                        <Button type="submit" loading={form.processing} loadingText="Création…">
                            Créer le devis
                        </Button>
                        <Link href="/quotations" className="inline-flex min-h-10 items-center rounded-field px-4 text-sm text-ink-muted">
                            Annuler
                        </Link>
                    </div>
                </form>
            </div>
        </SalesLayout>
    );
}

const input = 'w-full rounded-field border border-line-strong px-3 py-2';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-ink-muted">{label}</span>
            {children}
        </label>
    );
}
