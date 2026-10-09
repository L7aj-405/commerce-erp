import { Button, ButtonLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

type Entry = any;
export default function CommissionEntryShow({ entry, can }: { entry: Entry; can: { approve: boolean; markPaid: boolean } }) {
    const [processing, setProcessing] = useState(false);
    const action = (path: string) => { setProcessing(true); router.post(path, { entry_ids: [entry.id] }, { preserveScroll: true, onFinish: () => setProcessing(false) }); };
    return <ApplicationShell>
        <Head title={`Commission #${entry.id}`} />
        <PageHeader title={`Écriture de commission #${entry.id}`} description={`${entry.salesperson_name_snapshot ?? 'Commercial non attribué'} · ${formatDateTime(entry.occurred_at)}`} actions={<><ButtonLink href="/finance/commissions/ledger" variant="secondary">Retour au registre</ButtonLink>{entry.status === 'pending' && can.approve && <Button loading={processing} onClick={() => action('/finance/commissions/ledger/approve')}>Approuver</Button>}{entry.status === 'approved' && can.markPaid && <Button loading={processing} onClick={() => action('/finance/commissions/ledger/mark-paid')}>Marquer payée</Button>}</>} />
        <div className="grid gap-5 lg:grid-cols-2">
            <Card title="Calcul historique"><Row label="Revenu HT" value={formatMoney(entry.revenue_ht_snapshot)} /><Row label="Coût d’achat unitaire" value={entry.purchase_cost_snapshot === null ? 'Indisponible' : formatMoney(entry.purchase_cost_snapshot)} /><Row label="Coût total" value={formatMoney(entry.cost_total_snapshot)} /><Row label="Marge" value={formatMoney(entry.margin_amount_snapshot)} /><Row label="Marge %" value={`${entry.margin_rate_snapshot}%`} /><Row label="Commission %" value={`${entry.commission_rate_snapshot}%`} strong /><Row label="Commission" value={formatMoney(entry.commission_amount)} strong /></Card>
            <Card title="Règle et origine"><Row label="Règle" value={entry.commission_rule_set_name_snapshot} /><Row label="Tranche" value={`${entry.rule_min_margin_snapshot ?? '−∞'} ≤ marge < ${entry.rule_max_margin_snapshot ?? '+∞'}`} /><Row label="Date de vente" value={formatDate(entry.sale_date)} /><Row label="Commande" value={entry.sales_order?.order_number ?? `#${entry.sales_order_id}`} /><Row label="Produit / ligne" value={entry.product_name_snapshot ?? '—'} /><Row label="Référence" value={entry.line_reference_snapshot ?? '—'} /><Row label="Type" value={entry.entry_type} /><Row label="Statut" value={entry.status} /></Card>
            {entry.source_entry && <Card title="Écriture d’origine"><Row label="Écriture" value={`#${entry.source_entry.id}`} /><Row label="Commission d’origine" value={formatMoney(entry.source_entry.commission_amount)} /><Row label="Quantité reprise" value={String(entry.quantity_snapshot).replace(/^-/, '')} /><Row label="Retour source" value={entry.customer_return?.return_number ?? '—'} /><Row label="Motif" value={entry.reversal_reason ?? '—'} /></Card>}
            <Card title="Cycle de traitement"><Row label="Approuvée par" value={entry.approved_by?.name ?? '—'} /><Row label="Approuvée le" value={entry.approved_at ? formatDateTime(entry.approved_at) : '—'} /><Row label="Payée par" value={entry.paid_by?.name ?? '—'} /><Row label="Payée le" value={entry.paid_at ? formatDateTime(entry.paid_at) : '—'} /></Card>
        </div>
    </ApplicationShell>;
}
function Card({ title, children }: { title: string; children: ReactNode }) { return <section className="rounded-card border border-line bg-surface p-5"><h2 className="mb-4 font-semibold text-ink">{title}</h2><dl>{children}</dl></section>; }
function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) { return <div className="flex items-start justify-between gap-5 border-b border-line py-2.5 last:border-0"><dt className="text-sm text-ink-muted">{label}</dt><dd className={`text-right text-sm ${strong ? 'font-semibold text-ink' : 'text-ink'}`}>{value}</dd></div>; }
