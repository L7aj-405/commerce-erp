import CommissionTabs from '@/components/finance/CommissionTabs';
import { Button } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type Tier = { id?: number; min_margin_rate: string | null; max_margin_rate: string | null; commission_rate: string; sort_order?: number };
type RuleSet = {
    id: number; name: string; status: 'draft' | 'active' | 'archived'; effective_from: string | null; effective_until: string | null;
    activated_at: string | null; created_at: string; notes: string | null; tiers: Tier[];
};
type Simulation = { filters: Record<string, string | number | null>; rule_set_id: number; result: any } | null;
type Props = {
    organization: { id: number; name: string }; ruleSets: RuleSet[]; stores: { id: number; name: string; code: string }[];
    salespeople: { id: number; name: string }[]; simulation: Simulation;
    can: { manage: boolean; activate: boolean; simulate: boolean };
};

const emptyTiers: Tier[] = [
    { min_margin_rate: null, max_margin_rate: '10.0000', commission_rate: '0.0000' },
    { min_margin_rate: '10.0000', max_margin_rate: '20.0000', commission_rate: '5.0000' },
    { min_margin_rate: '20.0000', max_margin_rate: '30.0000', commission_rate: '8.0000' },
    { min_margin_rate: '30.0000', max_margin_rate: null, commission_rate: '12.0000' },
];

export default function CommissionRules({ organization, ruleSets, stores, salespeople, simulation, can }: Props) {
    const [editing, setEditing] = useState<RuleSet | null>(null);
    const form = useForm({ name: '', effective_from: '', notes: '', tiers: emptyTiers });
    const sim = useForm({ from: new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10), store_id: '', salesperson_id: '' });
    const [simulationSet, setSimulationSet] = useState<number | null>(ruleSets.find((item) => item.status === 'draft')?.id ?? ruleSets[0]?.id ?? null);
    const selectedSimulationSet = useMemo(() => ruleSets.find((item) => item.id === simulationSet), [ruleSets, simulationSet]);

    const beginCreate = () => { setEditing(null); form.setData({ name: '', effective_from: '', notes: '', tiers: emptyTiers.map((tier) => ({ ...tier })) }); };
    const beginEdit = (set: RuleSet) => { setEditing(set); form.setData({ name: set.name, effective_from: dateOnly(set.effective_from), notes: set.notes ?? '', tiers: set.tiers.map((tier) => ({ min_margin_rate: tier.min_margin_rate, max_margin_rate: tier.max_margin_rate, commission_rate: tier.commission_rate })) }); };
    const submit = () => editing
        ? form.put(`/finance/commissions/rules/${editing.id}`, { preserveScroll: true })
        : form.post('/finance/commissions/rules', { preserveScroll: true, onSuccess: beginCreate });
    const changeTier = (index: number, field: keyof Tier, value: string) => form.setData('tiers', form.data.tiers.map((tier, i) => i === index ? { ...tier, [field]: value === '' ? null : value } : tier));
    const addTier = () => form.setData('tiers', [...form.data.tiers, { min_margin_rate: '', max_margin_rate: null, commission_rate: '0.0000' }]);

    return (
        <ApplicationShell wide>
            <Head title="Règles de commission" />
            <PageHeader title="Règles de commission" description={`${organization.name} · Configuration versionnée et simulation sur les marges historiques`} />
            <CommissionTabs active="rules" />

            <div className="grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
                <section className="rounded-card border border-line bg-surface p-5">
                    <div className="mb-4 flex items-center justify-between"><h2 className="font-semibold text-ink">Versions</h2>{can.manage && <Button size="sm" variant="secondary" onClick={beginCreate}>Nouveau brouillon</Button>}</div>
                    <div className="space-y-3">
                        {ruleSets.length === 0 && <p className="rounded-field bg-raised p-4 text-sm text-ink-muted">Aucune règle configurée. Aucune commission n’est résolue tant qu’une version n’est pas explicitement activée.</p>}
                        {ruleSets.map((set) => (
                            <article key={set.id} className="rounded-field border border-line p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div><div className="flex items-center gap-2"><strong className="text-sm text-ink">{set.name}</strong><Status value={set.status} /></div><p className="mt-1 text-xs text-ink-muted">{dateOnly(set.effective_from) || 'Date à définir'} → {dateOnly(set.effective_until) || 'sans fin'} · {set.tiers.length} tranche(s)</p></div>
                                    <div className="flex flex-wrap gap-2">
                                        {set.status === 'draft' && can.manage && <Button size="sm" variant="secondary" onClick={() => beginEdit(set)}>Modifier</Button>}
                                        {can.manage && <Button size="sm" variant="ghost" onClick={() => router.post(`/finance/commissions/rules/${set.id}/duplicate`, {}, { preserveScroll: true })}>Dupliquer</Button>}
                                        {set.status === 'draft' && can.activate && <Button size="sm" onClick={() => router.post(`/finance/commissions/rules/${set.id}/activate`, {}, { preserveScroll: true })}>Activer</Button>}
                                        {set.status === 'draft' && can.manage && <Button size="sm" variant="danger" onClick={() => router.post(`/finance/commissions/rules/${set.id}/archive`, {}, { preserveScroll: true })}>Archiver</Button>}
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                </section>

                {can.manage && <section className="rounded-card border border-line bg-surface p-5">
                    <h2 className="font-semibold text-ink">{editing ? `Modifier · ${editing.name}` : 'Créer un brouillon'}</h2>
                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <Field label="Nom" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                        <Field label="Prise d’effet" error={form.errors.effective_from}><input type="date" className={inputClass} value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} /></Field>
                    </div>
                    <Field label="Notes" error={form.errors.notes}><textarea className={`${inputClass} min-h-20`} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                    <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[520px] text-sm"><thead><tr className="border-b border-line text-left text-xs text-ink-muted"><th className="py-2">Marge min (incluse)</th><th>Marge max (exclue)</th><th>Commission</th><th /></tr></thead><tbody>{form.data.tiers.map((tier, index) => <tr key={index} className="border-b border-line"><td className="py-2"><input className={smallInput} placeholder="−∞" value={tier.min_margin_rate ?? ''} onChange={(e) => changeTier(index, 'min_margin_rate', e.target.value)} /></td><td><input className={smallInput} placeholder="+∞" value={tier.max_margin_rate ?? ''} onChange={(e) => changeTier(index, 'max_margin_rate', e.target.value)} /></td><td><input className={smallInput} value={tier.commission_rate} onChange={(e) => changeTier(index, 'commission_rate', e.target.value)} /></td><td><button type="button" className="text-xs text-danger" onClick={() => form.setData('tiers', form.data.tiers.filter((_, i) => i !== index))}>Retirer</button></td></tr>)}</tbody></table></div>
                    {form.errors.tiers && <p className="mt-2 text-xs text-danger">{form.errors.tiers}</p>}
                    <p className="mt-2 text-xs text-ink-muted">Les tranches doivent couvrir sans interruption de −∞ à +∞. La borne basse est incluse, la borne haute exclue.</p>
                    <div className="mt-4 flex justify-between"><Button type="button" size="sm" variant="secondary" onClick={addTier}>Ajouter une tranche</Button><Button type="button" loading={form.processing} onClick={submit}>{editing ? 'Enregistrer' : 'Créer le brouillon'}</Button></div>
                </section>}
            </div>

            {can.simulate && <section className="mt-6 rounded-card border border-line bg-surface p-5">
                <h2 className="font-semibold text-ink">Simulation historique</h2><p className="mt-1 text-xs text-ink-muted">Lecture seule : utilise les instantanés de marge et l’attribution commerciale enregistrés. Les retours reçus sont imputés au commercial et à la règle de la vente d’origine.</p>
                <div className="mt-4 grid gap-3 md:grid-cols-5">
                    <Field label="Règle"><select className={inputClass} value={simulationSet ?? ''} onChange={(e) => setSimulationSet(Number(e.target.value))}>{ruleSets.filter((item) => item.status !== 'archived').map((item) => <option key={item.id} value={item.id}>{item.name} · {item.status}</option>)}</select></Field>
                    <Field label="Du"><input type="date" className={inputClass} value={sim.data.from} onChange={(e) => sim.setData('from', e.target.value)} /></Field>
                    <Field label="Au"><input type="date" className={inputClass} value={sim.data.to} onChange={(e) => sim.setData('to', e.target.value)} /></Field>
                    <Field label="Magasin"><select className={inputClass} value={sim.data.store_id} onChange={(e) => sim.setData('store_id', e.target.value)}><option value="">Tous</option>{stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                    <Field label="Commercial"><select className={inputClass} value={sim.data.salesperson_id} onChange={(e) => sim.setData('salesperson_id', e.target.value)}><option value="">Tous</option>{salespeople.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                </div>
                <div className="mt-4"><Button disabled={!selectedSimulationSet} loading={sim.processing} onClick={() => simulationSet && sim.post(`/finance/commissions/rules/${simulationSet}/simulate`, { preserveScroll: true })}>Simuler</Button></div>
                {simulation && <SimulationResult result={simulation.result} />}
            </section>}
        </ApplicationShell>
    );
}

function SimulationResult({ result }: { result: any }) {
    return <div className="mt-6"><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><Metric label="Lignes éligibles" value={String(result.eligible_line_count)} /><Metric label="Non évaluables" value={String(result.not_evaluable_line_count)} /><Metric label="CA HT couvert" value={formatMoney(result.covered_revenue)} /><Metric label="Marge brute" value={formatMoney(result.gross_margin)} /><Metric label="Commission estimée" value={formatMoney(result.estimated_commission)} /><Metric label="Commission / marge" value={result.estimated_commission_margin_rate ? `${result.estimated_commission_margin_rate}%` : '—'} /><Metric label="Commerciaux" value={String(result.salesperson_count)} /><Metric label="Commandes" value={String(result.order_count)} /></div><div className="mt-5 grid gap-5 lg:grid-cols-2"><Breakdown title="Par commercial" rows={result.by_salesperson.map((r: any) => ({ label: r.salesperson_name ?? 'Non attribué', ...r }))} /><Breakdown title="Par tranche" rows={result.by_tier.map((r: any) => ({ label: `${r.min_margin_rate ?? '−∞'} → ${r.max_margin_rate ?? '+∞'} · ${r.commission_rate}%`, ...r }))} /></div><p className="mt-4 text-xs text-ink-muted">Coût manquant : {result.missing_cost_line_count} · Sans commercial : {result.unattributed_line_count} · Sans règle : {result.no_rule_set_line_count} · Sans tranche : {result.no_matching_tier_line_count}</p></div>;
}
function Breakdown({ title, rows }: { title: string; rows: any[] }) { return <div><h3 className="mb-2 text-sm font-semibold text-ink">{title}</h3><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-line text-left text-xs text-ink-muted"><th className="py-2">Groupe</th><th className="text-right">Marge</th><th className="text-right">Commission</th></tr></thead><tbody>{rows.map((row, i) => <tr key={i} className="border-b border-line"><td className="py-2">{row.label}</td><td className="text-right">{formatMoney(row.gross_margin)}</td><td className="text-right">{formatMoney(row.estimated_commission)}</td></tr>)}</tbody></table></div></div>; }
function Metric({ label, value }: { label: string; value: string }) { return <div className="rounded-field bg-raised p-3"><p className="text-[11px] uppercase tracking-wide text-ink-faint">{label}</p><p className="mt-1 font-semibold text-ink">{value}</p></div>; }
function Status({ value }: { value: RuleSet['status'] }) { const label = value === 'active' ? 'Active' : value === 'draft' ? 'Brouillon' : 'Archivée'; return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${value === 'active' ? 'bg-success-soft text-success' : value === 'draft' ? 'bg-warning-soft text-warning' : 'bg-raised text-ink-muted'}`}>{label}</span>; }
function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) { return <label className="mt-3 block text-xs font-medium text-ink-muted"><span>{label}</span><div className="mt-1">{children}</div>{error && <span className="mt-1 block text-danger">{error}</span>}</label>; }
const inputClass = 'w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none';
const smallInput = 'w-32 rounded-field border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-primary focus:outline-none';
const dateOnly = (value: string | null) => value ? value.slice(0, 10) : '';
