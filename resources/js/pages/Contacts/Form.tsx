import PageHeader from '@/components/ui/PageHeader';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type LinkedOption = { id: number; display_name?: string; name?: string };
type Contact = {
    id: number;
    full_name: string;
    company_name: string | null;
    job_title: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    contact_type: string;
    notes: string | null;
    active: boolean;
    customer: { id: number; name: string } | null;
    supplier: { id: number; name: string } | null;
} | null;
type Props = { contact: Contact; linkedOptions: { customers: LinkedOption[]; suppliers: LinkedOption[] } };

const input = 'w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm text-ink outline-none transition-soft focus:border-primary';
const blank = { full_name: '', company_name: '', job_title: '', email: '', phone: '', whatsapp: '', contact_type: 'client', customer_id: '', supplier_id: '', notes: '', active: true };

export default function ContactForm({ contact, linkedOptions }: Props) {
    const form = useForm({
        full_name: contact?.full_name ?? blank.full_name,
        company_name: contact?.company_name ?? blank.company_name,
        job_title: contact?.job_title ?? blank.job_title,
        email: contact?.email ?? blank.email,
        phone: contact?.phone ?? blank.phone,
        whatsapp: contact?.whatsapp ?? blank.whatsapp,
        contact_type: contact?.contact_type ?? blank.contact_type,
        customer_id: contact?.customer?.id?.toString() ?? '',
        supplier_id: contact?.supplier?.id?.toString() ?? '',
        notes: contact?.notes ?? blank.notes,
        active: contact?.active ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (contact) form.patch(`/contacts/${contact.id}`);
        else form.post('/contacts');
    };

    return (
        <SalesLayout>
            <Head title={contact ? 'Modifier le contact' : 'Nouveau contact'} />
            <PageHeader
                title={contact ? 'Modifier le contact' : 'Nouveau contact'}
                description="Un contact est une entrée d’adresse pour votre organisation. Ce n’est pas un utilisateur ERP."
                actions={<Link href={contact ? `/contacts/${contact.id}` : '/contacts'} className="inline-flex min-h-10 items-center rounded-field border border-line-strong px-4 text-sm text-ink transition-soft hover:bg-sage">Retour</Link>}
            />
            <form onSubmit={submit} className="rounded-card border border-line bg-surface p-5">
                <div className="grid gap-4 md:grid-cols-2">
                    <Field label="Nom complet" error={form.errors.full_name}><input required value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} className={input} /></Field>
                    <Field label="Type" error={form.errors.contact_type}>
                        <select value={form.data.contact_type} onChange={(e) => form.setData('contact_type', e.target.value)} className={input}>
                            <option value="client">Client</option>
                            <option value="supplier">Fournisseur</option>
                            <option value="internal">Interne</option>
                            <option value="other">Autre</option>
                        </select>
                    </Field>
                    <Field label="Société / organisation" error={form.errors.company_name}><input value={form.data.company_name} onChange={(e) => form.setData('company_name', e.target.value)} className={input} /></Field>
                    <Field label="Fonction" error={form.errors.job_title}><input value={form.data.job_title} onChange={(e) => form.setData('job_title', e.target.value)} className={input} /></Field>
                    <Field label="Email" error={form.errors.email}><input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={input} /></Field>
                    <Field label="Téléphone" error={form.errors.phone}><input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className={input} /></Field>
                    <Field label="WhatsApp" error={form.errors.whatsapp}><input value={form.data.whatsapp} onChange={(e) => form.setData('whatsapp', e.target.value)} className={input} /></Field>
                    <Field label="Client lié" error={form.errors.customer_id}>
                        <select value={form.data.customer_id} onChange={(e) => form.setData('customer_id', e.target.value)} className={input}>
                            <option value="">Aucun</option>
                            {linkedOptions.customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.display_name}</option>)}
                        </select>
                    </Field>
                    <Field label="Fournisseur lié" error={form.errors.supplier_id}>
                        <select value={form.data.supplier_id} onChange={(e) => form.setData('supplier_id', e.target.value)} className={input}>
                            <option value="">Aucun</option>
                            {linkedOptions.suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Notes" error={form.errors.notes}><textarea rows={4} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={input} /></Field>
                </div>
                <label className="mt-4 flex items-center gap-2 text-sm text-ink-muted">
                    <input type="checkbox" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} />
                    Contact actif
                </label>
                {form.data.email && <p className="mt-3 rounded-field border border-warning/30 bg-warning-soft px-3 py-2 text-xs text-warning">Si cet email existe déjà dans votre répertoire, l’enregistrement reste autorisé : vérifiez simplement qu’il s’agit du bon interlocuteur.</p>}
                <div className="mt-5 flex justify-end gap-2">
                    <Link href={contact ? `/contacts/${contact.id}` : '/contacts'} className="inline-flex min-h-10 items-center rounded-field px-4 text-sm text-ink-muted hover:bg-sage">Annuler</Link>
                    <button type="submit" disabled={form.processing} className="inline-flex min-h-10 items-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg disabled:opacity-60">{form.processing ? 'Enregistrement…' : 'Enregistrer'}</button>
                </div>
            </form>
        </SalesLayout>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <label className="block text-sm"><span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-muted">{label}</span>{children}{error && <span className="mt-1 block text-xs text-danger">{error}</span>}</label>;
}
