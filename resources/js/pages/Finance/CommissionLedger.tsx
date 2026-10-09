import CommissionTabs from '@/components/finance/CommissionTabs';
import { Button } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

type Entry = {
    id: number; occurred_at: string; sale_date: string; salesperson_name_snapshot: string | null; product_name_snapshot: string | null;
    entry_type: string; status: string; margin_amount_snapshot: string; commission_rate_snapshot: string; commission_amount: string;
    sales_order: { id: number; order_number: string }; customer_return?: { id: number; return_number: string } | null;
};
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
type Filters = { from: string; to: string; store_id: number | null; salesperson_id: number | null; status: string | null; entry_type: string | null; order: string | null };
type Props = {
    organization: { id: number; name: string }; entries: Page<Entry>; filters: Filters;
    stores: { id: number; name: string; code: string }[]; salespeople: { id: number; name: string }[];
    summary: { gross_earned: string; reversals: string; net: string; pending: string; approved: string; paid: string; entry_count: number };
    reconciliation: { counts: Record<string, number>; rows: { sales_order_id: number; order_number: string; sale_date: string; product: string; salesperson: string | null; reason: string }[] } | null;
    can: { approve: boolean; markPaid: boolean; reconcile: boolean };
};

export default function CommissionLedger({ organization, entries, filters, stores, salespeople, summary, reconciliation, can }: Props) {
    const [local, setLocal] = useState({ ...filters, store_id: filters.store_id?.toString() ?? '', salesperson_id: filters.salesperson_id?.toString() ?? '', status: filters.status ?? '', entry_type: filters.entry_type ?? '', order: filters.order ?? '' });
    const [selected, setSelected] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);
    const apply = () => router.get('/finance/commissions/ledger', local, { preserveState: true, replace: true });
    const post = (path: string, data: any) => { setProcessing(true); router.post(path, data, { preserveScroll: true, onFinish: () => setProcessing(false), onSuccess: () => setSelected([]) }); };
    const allVisible = entries.data.length > 0 && entries.data.every((entry) => selected.includes(entry.id));
    const allSelectedHaveStatus = (status: string) => selected.length > 0 && selected.every((id) => entries.data.find((entry) => entry.id === id)?.status === status);

    return <ApplicationShell wide>
        <Head title="Registre des commissions" />
        <PageHeader title="Registre des commissions" description={`${organization.name} · Écritures historiques, approbation et règlement`} />
        <CommissionTabs active="ledger" />

        <section className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <Metric label="Brut acquis" value={summary.gross_earned} /><Metric label="Reprises" value={summary.reversals} negative />
            <Metric label="Net" value={summary.net} /><Metric label="En attente" value={summary.pending} />
            <Metric label="Approuvé" value={summary.approved} /><Metric label="Payé" value={summary.paid} />
        </section>

        <section className="mb-5 rounded-card border border-line bg-surface p-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-7">
                <Field label="Du"><input type="date" className={input} value={local.from} onChange={(e) => setLocal({ ...local, from: e.target.value })} /></Field>
                <Field label="Au"><input type="date" className={input} value={local.to} onChange={(e) => setLocal({ ...local, to: e.target.value })} /></Field>
                <Field label="Commercial"><select className={input} value={local.salesperson_id} onChange={(e) => setLocal({ ...local, salesperson_id: e.target.value })}><option value="">Tous</option>{salespeople.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                <Field label="Magasin"><select className={input} value={local.store_id} onChange={(e) => setLocal({ ...local, store_id: e.target.value })}><option value="">Tous</option>{stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                <Field label="Statut"><select className={input} value={local.status} onChange={(e) => setLocal({ ...local, status: e.target.value })}><option value="">Tous</option><option value="pending">En attente</option><option value="approved">Approuvé</option><option value="paid">Payé</option></select></Field>
                <Field label="Type"><select className={input} value={local.entry_type} onChange={(e) => setLocal({ ...local, entry_type: e.target.value })}><option value="">Tous</option><option value="sale">Vente</option><option value="return_reversal">Retour</option><option value="correction">Correction</option><option value="cancellation">Annulation</option></select></Field>
                <Field label="Commande"><input className={input} value={local.order} placeholder="SO-…" onChange={(e) => setLocal({ ...local, order: e.target.value })} /></Field>
            </div><div className="mt-3 flex justify-end"><Button size="sm" variant="secondary" onClick={apply}>Appliquer</Button></div>
        </section>

        {(can.approve || can.markPaid) && <div className="mb-3 flex flex-wrap gap-2">
            {can.approve && <Button size="sm" disabled={!allSelectedHaveStatus('pending')} loading={processing} onClick={() => post('/finance/commissions/ledger/approve', { entry_ids: selected })}>Approuver la sélection</Button>}
            {can.markPaid && <Button size="sm" variant="secondary" disabled={!allSelectedHaveStatus('approved')} loading={processing} onClick={() => post('/finance/commissions/ledger/mark-paid', { entry_ids: selected })}>Marquer payée</Button>}
        </div>}

        <section className="overflow-x-auto rounded-card border border-line bg-surface">
            <table className="w-full min-w-[980px] text-left text-sm"><thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint"><tr>
                <th className="px-3 py-2"><input aria-label="Tout sélectionner" type="checkbox" checked={allVisible} onChange={() => setSelected(allVisible ? selected.filter((id) => !entries.data.some((entry) => entry.id === id)) : [...new Set([...selected, ...entries.data.map((entry) => entry.id)])])} /></th>
                <th className="px-3 py-2">Date</th><th className="px-3 py-2">Commercial</th><th className="px-3 py-2">Commande</th><th className="px-3 py-2">Produit / ligne</th><th className="px-3 py-2">Type</th><th className="px-3 py-2 text-right">Marge</th><th className="px-3 py-2 text-right">Taux</th><th className="px-3 py-2 text-right">Commission</th><th className="px-3 py-2">Statut</th>
            </tr></thead><tbody>{entries.data.map((entry) => <tr key={entry.id} className="border-t border-line">
                <td className="px-3 py-2"><input aria-label={`Sélectionner ${entry.id}`} type="checkbox" checked={selected.includes(entry.id)} onChange={() => setSelected(selected.includes(entry.id) ? selected.filter((id) => id !== entry.id) : [...selected, entry.id])} /></td>
                <td className="px-3 py-2 text-ink-muted">{formatDate(entry.occurred_at)}</td><td className="px-3 py-2">{entry.salesperson_name_snapshot ?? 'Non attribué'}</td>
                <td className="px-3 py-2"><Link className="font-medium text-primary" href={`/sales/orders/${entry.sales_order.id}`}>{entry.sales_order.order_number}</Link></td>
                <td className="px-3 py-2"><Link href={`/finance/commissions/ledger/${entry.id}`} className="font-medium text-ink hover:text-primary">{entry.product_name_snapshot ?? `Ligne #${entry.id}`}</Link></td>
                <td className="px-3 py-2"><TypeBadge value={entry.entry_type} /></td><td className="px-3 py-2 text-right">{formatMoney(entry.margin_amount_snapshot)}</td><td className="px-3 py-2 text-right">{entry.commission_rate_snapshot}%</td>
                <td className={`px-3 py-2 text-right font-semibold ${Number(entry.commission_amount) < 0 ? 'text-danger' : 'text-ink'}`}>{formatMoney(entry.commission_amount)}</td><td className="px-3 py-2"><StatusBadge value={entry.status} /></td>
            </tr>)}</tbody></table>
            {entries.data.length === 0 && <p className="p-8 text-center text-sm text-ink-faint">Aucune écriture pour ces filtres.</p>}
        </section><Pagination links={entries.links} />

        {can.reconcile && reconciliation && <section className="mt-6 rounded-card border border-line bg-surface p-5">
            <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold text-ink">Réconciliation</h2><p className="mt-1 text-xs text-ink-muted">Détecte les lignes confirmées sans écriture. La génération utilise uniquement les instantanés historiques et la règle applicable à la date de vente.</p></div><Button variant="secondary" loading={processing} disabled={(reconciliation.counts.eligible_missing ?? 0) === 0} onClick={() => post('/finance/commissions/ledger/reconcile', { from: filters.from, to: filters.to, store_id: filters.store_id, salesperson_id: filters.salesperson_id })}>Générer les écritures éligibles manquantes</Button></div>
            <div className="mt-4 flex flex-wrap gap-2">{Object.entries(reconciliation.counts).map(([reason, count]) => <span key={reason} className="rounded-full bg-raised px-3 py-1 text-xs text-ink-muted">{reasonLabel(reason)} : {count}</span>)}</div>
            {reconciliation.rows.length > 0 && <div className="mt-4 overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-line text-left text-xs text-ink-muted"><th className="py-2">Commande</th><th>Ligne</th><th>Commercial</th><th>Motif</th></tr></thead><tbody>{reconciliation.rows.map((row, index) => <tr key={`${row.sales_order_id}-${index}`} className="border-b border-line"><td className="py-2"><Link href={`/sales/orders/${row.sales_order_id}`}>{row.order_number}</Link></td><td>{row.product}</td><td>{row.salesperson ?? '—'}</td><td>{reasonLabel(row.reason)}</td></tr>)}</tbody></table></div>}
        </section>}
    </ApplicationShell>;
}

function Metric({ label, value, negative = false }: { label: string; value: string; negative?: boolean }) { return <div className="rounded-card border border-line bg-surface p-4"><p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p><p className={`mt-1 text-lg font-semibold ${negative && Number(value) < 0 ? 'text-danger' : 'text-ink'}`}>{formatMoney(value)}</p></div>; }
function Field({ label, children }: { label: string; children: ReactNode }) { return <label className="text-xs font-medium text-ink-muted">{label}<div className="mt-1">{children}</div></label>; }
function StatusBadge({ value }: { value: string }) { const labels: Record<string,string> = { pending: 'En attente', approved: 'Approuvé', paid: 'Payé' }; return <span className="rounded-full bg-raised px-2 py-1 text-xs font-medium text-ink-muted">{labels[value] ?? value}</span>; }
function TypeBadge({ value }: { value: string }) { const labels: Record<string,string> = { sale: 'Vente', return_reversal: 'Retour', correction: 'Correction', cancellation: 'Annulation', manual_adjustment: 'Ajustement' }; return <span>{labels[value] ?? value}</span>; }
const reasonLabel = (value: string) => ({ eligible_missing: 'Éligible sans écriture', missing_salesperson: 'Commercial manquant', missing_cost: 'Coût manquant', margin_unavailable: 'Marge indisponible', no_rule_set: 'Aucune règle applicable', no_matching_tier: 'Aucune tranche applicable', missing_return_reversal: 'Reprise de retour manquante', over_reversed_quantity: 'Quantité reprise excessive' }[value] ?? value);
const input = 'w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none';
