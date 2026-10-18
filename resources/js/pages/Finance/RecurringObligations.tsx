import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { Sheet } from '@/components/ui/Sheet';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatDateTime, formatEditableNumber, formatMoney } from '@/utils/format';
import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent, type InputHTMLAttributes, type ReactNode, useState } from 'react';

type Option = { value: string; label: string };
type Entity = { id: number; name: string; code?: string; currency_code?: string };
type Occurrence = { id: number; number: string; period: string; due_date: string; amount: string };
type Template = {
    id: number; number: string; category: string; category_label: string; label: string; store: Entity | null;
    supplier: Entity | null; supplier_id: number | null; beneficiary_name: string | null; beneficiary: string;
    reference: string | null; planned_amount: string; currency_code: string; planned_payment_method: string | null;
    preferred_financial_account_id: number | null; due_day_mode: 'fixed' | 'end_of_month'; due_day: number | null;
    due_label: string; start_date: string; end_date: string | null; active: boolean; notes: string | null;
    created_by: string | null; created_at: string | null; occurrences_count: number; last_period: string | null;
    next_due_date: string | null; occurrences: Occurrence[];
};
type Paginator = { data: Template[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
type Props = {
    organization: { id: number; name: string }; filters: { status?: string; category?: string; store_id?: number; supplier_id?: number; search?: string };
    templates: Paginator; stores: Entity[]; suppliers: Entity[]; financialAccounts: Entity[]; categories: Option[];
    paymentMethods: Option[]; currencyCode: string; canManage: boolean;
};

const inputClass = 'h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none focus:border-primary focus:ring-4 focus:ring-primary/10';
const emptyTemplate = (currency: string) => ({
    store_id: '', category: 'fixed_expense', label: '', supplier_id: '', beneficiary_name: '', reference: '', planned_amount: '',
    currency_code: currency, planned_payment_method: '', preferred_financial_account_id: '', due_day_mode: 'fixed', due_day: '1',
    start_date: new Date().toISOString().slice(0, 10), end_date: '', active: true, notes: '',
});

export default function RecurringObligations(props: Props) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Template | null>(null);
    const [detail, setDetail] = useState<Template | null>(null);
    const [search, setSearch] = useState(props.filters.search ?? '');
    const form = useForm(emptyTemplate(props.currencyCode));

    const openCreate = () => { form.setData(emptyTemplate(props.currencyCode)); form.clearErrors(); setCreating(true); };
    const openEdit = (row: Template) => {
        form.setData({
            store_id: row.store ? String(row.store.id) : '', category: row.category, label: row.label,
            supplier_id: row.supplier_id ? String(row.supplier_id) : '', beneficiary_name: row.beneficiary_name ?? '', reference: row.reference ?? '',
            planned_amount: formatEditableNumber(row.planned_amount), currency_code: row.currency_code,
            planned_payment_method: row.planned_payment_method ?? '', preferred_financial_account_id: row.preferred_financial_account_id ? String(row.preferred_financial_account_id) : '',
            due_day_mode: row.due_day_mode, due_day: row.due_day ? String(row.due_day) : '', start_date: row.start_date,
            end_date: row.end_date ?? '', active: row.active, notes: row.notes ?? '',
        });
        form.clearErrors(); setEditing(row);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing) form.patch(`/finance/situation-fin-de-mois/charges-recurrentes/${editing.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
        else form.post('/finance/situation-fin-de-mois/charges-recurrentes', { preserveScroll: true, onSuccess: () => setCreating(false) });
    };
    const applyFilters = (next: Record<string, string | number>) => router.get('/finance/situation-fin-de-mois/charges-recurrentes', {
        status: props.filters.status ?? '', category: props.filters.category ?? '', store_id: props.filters.store_id ?? '', supplier_id: props.filters.supplier_id ?? '', search: props.filters.search ?? '', ...next,
    }, { preserveState: true, preserveScroll: true, replace: true });

    return <ApplicationShell wide>
        <Head title="Charges récurrentes" />
        <PageHeader title="Charges récurrentes" description={`${props.organization.name} · Ces modèles contrôlent uniquement les futures obligations non générées.`} actions={<><ButtonLink href="/finance/situation-fin-de-mois" variant="secondary">Situation fin de mois</ButtonLink>{props.canManage && <Button onClick={openCreate}>Ajouter une charge récurrente</Button>}</>} />

        <section className="mb-5 rounded-card border border-line bg-surface p-4">
            <form className="grid gap-3 xl:grid-cols-[minmax(220px,1fr)_150px_170px_180px_190px_auto]" onSubmit={(e) => { e.preventDefault(); applyFilters({ search }); }}>
                <input className={inputClass} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="N°, libellé, bénéficiaire…" />
                <select className={inputClass} value={props.filters.status ?? ''} onChange={(e) => applyFilters({ status: e.target.value })}><option value="">Tous les statuts</option><option value="active">Actives</option><option value="inactive">Inactives</option></select>
                <select className={inputClass} value={props.filters.category ?? ''} onChange={(e) => applyFilters({ category: e.target.value })}><option value="">Tous les types</option>{props.categories.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
                <select className={inputClass} value={props.filters.store_id ?? ''} onChange={(e) => applyFilters({ store_id: e.target.value })}><option value="">Toutes les boutiques</option>{props.stores.map((store) => <option key={store.id} value={store.id}>{store.name}</option>)}</select>
                <select className={inputClass} value={props.filters.supplier_id ?? ''} onChange={(e) => applyFilters({ supplier_id: e.target.value })}><option value="">Tous les fournisseurs</option>{props.suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select>
                <div className="flex gap-2"><Button type="submit" variant="secondary">Rechercher</Button><Button type="button" variant="ghost" onClick={() => router.get('/finance/situation-fin-de-mois/charges-recurrentes')}>Effacer</Button></div>
            </form>
        </section>

        {props.templates.data.length === 0 ? <EmptyState title="Aucune charge récurrente." description="Créez un modèle pour générer chaque mois une obligation financière indépendante." actions={props.canManage ? <Button onClick={openCreate}>Ajouter une charge récurrente</Button> : undefined} /> :
            <section className="overflow-hidden rounded-card border border-line bg-surface"><div className="overflow-x-auto"><table className="w-full min-w-[1050px] text-sm"><thead className="bg-raised text-left text-[11px] uppercase tracking-wide text-ink-muted"><tr>{['Libellé','Type','Bénéficiaire','Montant','Échéance','Début','Fin','Statut','Prochaine échéance','Actions'].map((label) => <th key={label} className="px-4 py-3 font-semibold">{label}</th>)}</tr></thead><tbody className="divide-y divide-line">{props.templates.data.map((row) => <tr key={row.id}>
                <td className="px-4 py-3"><button onClick={() => setDetail(row)} className="font-medium text-ink hover:underline">{row.label}</button><p className="text-xs text-ink-muted">{row.number}{row.store ? ` · ${row.store.name}` : ' · Organisation entière'}</p></td>
                <td className="px-4 py-3">{row.category_label}</td><td className="px-4 py-3">{row.beneficiary}</td><td className="px-4 py-3 text-right font-medium tabular-nums">{formatMoney(row.planned_amount, row.currency_code)}</td>
                <td className="px-4 py-3">{row.due_label}</td><td className="px-4 py-3">{formatDate(row.start_date)}</td><td className="px-4 py-3">{row.end_date ? formatDate(row.end_date) : 'Sans fin'}</td>
                <td className="px-4 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${row.active ? 'bg-success-soft text-success' : 'bg-raised text-ink-muted'}`}>{row.active ? 'Active' : 'Inactive'}</span></td>
                <td className="px-4 py-3">{row.next_due_date ? formatDate(row.next_due_date) : '—'}</td>
                <td className="px-4 py-3"><div className="flex gap-1"><Button size="sm" variant="ghost" onClick={() => setDetail(row)}>Voir</Button>{props.canManage && <Button size="sm" variant="secondary" onClick={() => openEdit(row)}>Modifier</Button>}</div></td>
            </tr>)}</tbody></table></div></section>}
        <Pagination links={props.templates.links} />

        <Sheet open={creating || !!editing} onClose={() => { setCreating(false); setEditing(null); }} title={editing ? 'Modifier la charge récurrente' : 'Ajouter une charge récurrente'} description="Les changements s’appliquent uniquement aux futures occurrences non générées." widthClassName="max-w-2xl" footer={<div className="flex justify-end gap-2"><Button variant="secondary" onClick={() => { setCreating(false); setEditing(null); }}>Annuler</Button><Button form="recurring-form" type="submit" loading={form.processing}>Enregistrer</Button></div>}>
            <RecurringForm form={form} {...props} onSubmit={submit} />
        </Sheet>

        <Sheet open={!!detail} onClose={() => setDetail(null)} title={detail?.number} description="Modèle de charge récurrente" widthClassName="max-w-xl">
            {detail && <div className="space-y-5 p-5"><div className="grid gap-3 sm:grid-cols-2"><Info label="Libellé" value={detail.label}/><Info label="Montant" value={formatMoney(detail.planned_amount, detail.currency_code)}/><Info label="Échéance" value={detail.due_label}/><Info label="Période" value={`${formatDate(detail.start_date)} → ${detail.end_date ? formatDate(detail.end_date) : 'sans fin'}`}/><Info label="Créée par" value={detail.created_by ?? '—'}/><Info label="Créée le" value={detail.created_at ? formatDateTime(detail.created_at) : '—'}/></div><section><h3 className="mb-2 text-sm font-semibold">Dernières obligations générées</h3>{detail.occurrences.length === 0 ? <p className="text-sm text-ink-muted">Aucune occurrence générée.</p> : <div className="divide-y divide-line rounded-field border border-line">{detail.occurrences.map((item) => <div key={item.id} className="flex justify-between gap-3 px-3 py-2 text-sm"><span>{item.number} · {item.period}</span><span className="font-medium">{formatMoney(item.amount, detail.currency_code)}</span></div>)}</div>}</section>{props.canManage && <div className="flex flex-wrap gap-2"><Button variant="secondary" onClick={() => { setDetail(null); openEdit(detail); }}>Modifier le modèle</Button>{detail.active ? <Button variant="danger" onClick={() => router.post(`/finance/situation-fin-de-mois/charges-recurrentes/${detail.id}/deactivate`, {}, { preserveScroll: true, onSuccess: () => setDetail(null) })}>Désactiver</Button> : <Button onClick={() => router.post(`/finance/situation-fin-de-mois/charges-recurrentes/${detail.id}/reactivate`, {}, { preserveScroll: true, onSuccess: () => setDetail(null) })}>Réactiver</Button>}</div>}</div>}
        </Sheet>
    </ApplicationShell>;
}

function RecurringForm({ form, stores, suppliers, financialAccounts, categories, paymentMethods, onSubmit }: Props & { form: ReturnType<typeof useForm>; onSubmit: (event: FormEvent) => void }) {
    return <form id="recurring-form" onSubmit={onSubmit} className="grid gap-4 p-5">
        <div className="grid gap-4 sm:grid-cols-2"><Select label="Type" value={form.data.category as string} onChange={(value) => form.setData('category', value)} error={form.errors.category}>{categories.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select><Field label="Libellé" value={form.data.label as string} onChange={(value) => form.setData('label', value)} error={form.errors.label}/></div>
        <div className="grid gap-4 sm:grid-cols-2"><Select label="Boutique (facultatif)" value={form.data.store_id as string} onChange={(value) => form.setData('store_id', value)} error={form.errors.store_id}><option value="">Organisation entière</option>{stores.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select><Select label="Fournisseur (facultatif)" value={form.data.supplier_id as string} onChange={(value) => form.setData('supplier_id', value)} error={form.errors.supplier_id}><option value="">Aucun</option>{suppliers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></div>
        <div className="grid gap-4 sm:grid-cols-2"><Field label="Bénéficiaire libre" value={form.data.beneficiary_name as string} onChange={(value) => form.setData('beneficiary_name', value)}/><Field label="Référence" value={form.data.reference as string} onChange={(value) => form.setData('reference', value)}/></div>
        <div className="grid gap-4 sm:grid-cols-2"><Field label="Montant fixe" type="number" step="0.0001" min="0.0001" value={form.data.planned_amount as string} onChange={(value) => form.setData('planned_amount', value)} error={form.errors.planned_amount}/><Select label="Règle d’échéance" value={form.data.due_day_mode as string} onChange={(value) => form.setData('due_day_mode', value)}><option value="fixed">Jour fixe (1 à 28)</option><option value="end_of_month">Fin du mois</option></Select></div>
        {form.data.due_day_mode === 'fixed' && <Field label="Jour du mois" type="number" min="1" max="28" value={form.data.due_day as string} onChange={(value) => form.setData('due_day', value)} error={form.errors.due_day}/>} 
        <div className="grid gap-4 sm:grid-cols-2"><Field label="Date de début" type="date" value={form.data.start_date as string} onChange={(value) => form.setData('start_date', value)} error={form.errors.start_date}/><Field label="Date de fin (facultatif)" type="date" value={form.data.end_date as string} onChange={(value) => form.setData('end_date', value)} error={form.errors.end_date}/></div>
        <div className="grid gap-4 sm:grid-cols-2"><Select label="Mode de paiement prévu" value={form.data.planned_payment_method as string} onChange={(value) => form.setData('planned_payment_method', value)}><option value="">Non défini</option>{paymentMethods.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select><Select label="Compte préféré" value={form.data.preferred_financial_account_id as string} onChange={(value) => form.setData('preferred_financial_account_id', value)}><option value="">Non défini</option>{financialAccounts.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.code}</option>)}</Select></div>
        <label><Label>Notes</Label><textarea className="min-h-24 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" value={form.data.notes as string} onChange={(e) => form.setData('notes', e.target.value)}/></label>
    </form>;
}

function Label({ children }: { children: ReactNode }) { return <span className="mb-1.5 block text-[13px] font-medium text-ink">{children}</span>; }
function Field({ label, value, onChange, error, ...rest }: { label: string; value: string; onChange: (value: string) => void; error?: string } & Omit<InputHTMLAttributes<HTMLInputElement>, 'value'|'onChange'>) { return <label><Label>{label}</Label><input {...rest} className={inputClass} value={value} onChange={(e) => onChange(e.target.value)}/>{error && <p className="mt-1 text-xs text-danger">{error}</p>}</label>; }
function Select({ label, value, onChange, error, children }: { label: string; value: string; onChange: (value: string) => void; error?: string; children: ReactNode }) { return <label><Label>{label}</Label><select className={inputClass} value={value} onChange={(e) => onChange(e.target.value)}>{children}</select>{error && <p className="mt-1 text-xs text-danger">{error}</p>}</label>; }
function Info({ label, value }: { label: string; value: string }) { return <div className="rounded-field border border-line p-3"><p className="text-xs text-ink-muted">{label}</p><p className="mt-1 text-sm font-medium text-ink">{value}</p></div>; }
