import { DownloadLink } from '@/components/ui/Button';
import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Situation = {
    period: string;
    label: string;
    ventes: string;
    ventes_nettes: string;
    facturation: string;
    facturation_brute: string;
    avoirs: string;
    encaissements: string;
    remboursements: string;
    net_encaisse: string;
    creances_debut: string;
    creances_fin: string;
    obligations_remboursement_debut: string;
    obligations_remboursement: string;
    position_nette_debut: string;
    position_nette_fin: string;
    reconciliation: { expected_fin: string; variance: string; unapplied_payments: string };
    customer_position: { expected_fin: string; variance: string };
};

type Props = {
    organization: { id: number; name: string };
    period: string;
    storeId: number | null;
    stores: FinanceStore[];
    situation: Situation;
    can: { receivables: boolean; export: boolean };
};

export default function FinanceSituation({ organization, period, storeId, stores, situation, can }: Props) {
    const q = (extra: Record<string, string | number | undefined> = {}) => ({ month: period, store_id: storeId ?? '', ...extra });

    const hasVariance = situation.customer_position.variance !== '0.0000' && Number(situation.customer_position.variance) !== 0;

    return (
        <ApplicationShell wide>
            <Head title="Situation mensuelle" />
            <PageHeader
                title="Situation mensuelle"
                description={`${organization.name} · ${situation.label}`}
                actions={
                    can.export ? (
                        <div className="flex gap-2">
                            <DownloadLink href={`/finance/export/xlsx?${new URLSearchParams(q() as Record<string, string>).toString()}`} variant="secondary" size="sm">
                                Export XLSX
                            </DownloadLink>
                            <DownloadLink href={`/finance/export/pdf?${new URLSearchParams(q() as Record<string, string>).toString()}`} variant="secondary" size="sm">
                                Export PDF
                            </DownloadLink>
                        </div>
                    ) : undefined
                }
            />

            <FinanceFilters path="/finance" period={period} storeId={storeId} stores={stores} />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <MetricCard
                    label="Ventes brutes"
                    sublabel="Commandes confirmées (sale_date), avant avoirs"
                    value={situation.ventes}
                    href={`/finance/ventes?${new URLSearchParams(q() as Record<string, string>).toString()}`}
                />
                <MetricCard label="Ventes nettes" sublabel="Ventes brutes − avoirs émis dans la période" value={situation.ventes_nettes} />
                <MetricCard
                    label="Facturation brute"
                    sublabel="Factures émises (invoice_date)"
                    value={situation.facturation_brute}
                    href={`/finance/facturation?${new URLSearchParams(q() as Record<string, string>).toString()}`}
                />
                <MetricCard label="Avoirs émis" sublabel="Avoirs émis (credit_note_date)" value={situation.avoirs} />
                <MetricCard label="Facturation nette" sublabel="Factures − avoirs" value={situation.facturation} />
                <MetricCard
                    label="Encaissements"
                    sublabel="Encaissements bruts (payment_date)"
                    value={situation.encaissements}
                    href={`/finance/encaissements?${new URLSearchParams(q() as Record<string, string>).toString()}`}
                />
                <MetricCard label="Remboursements" sublabel="Sorties enregistrées (refund_date)" value={situation.remboursements} />
                <MetricCard label="Net encaissé" sublabel="Encaissements − remboursements" value={situation.net_encaisse} />
                <MetricCard label="À rembourser" sublabel="Excédent client après avoirs" value={situation.obligations_remboursement} />
                {can.receivables ? (
                    <MetricCard
                        label="Créances"
                        sublabel="Solde en fin de période"
                        value={situation.creances_fin}
                        href={`/finance/creances?${new URLSearchParams(q() as Record<string, string>).toString()}`}
                    />
                ) : (
                    <MetricCard label="Créances" sublabel="Accès non autorisé" value={null} />
                )}
            </div>

            <section className="mt-8 rounded-card border border-line bg-surface p-5">
                <h2 className="mb-4 text-sm font-semibold text-ink">Réconciliation de la position client</h2>
                <table className="w-full max-w-xl text-sm">
                    <tbody>
                        <ReconciliationRow label="Position nette début (créances − à rembourser)" value={situation.position_nette_debut} />
                        <ReconciliationRow label="+ Facturation nette" value={situation.facturation} />
                        <ReconciliationRow label="− Encaissements nets" value={situation.net_encaisse} />
                        <tr className="border-t border-line-strong font-semibold text-ink">
                            <td className="py-2">= Position nette fin</td>
                            <td className="py-2 text-right">{formatMoney(situation.position_nette_fin)}</td>
                        </tr>
                        <ReconciliationRow label="dont créances clients" value={situation.creances_fin} />
                        <ReconciliationRow label="dont obligations de remboursement" value={situation.obligations_remboursement} />
                    </tbody>
                </table>
                {hasVariance && (
                    <p className="mt-3 rounded-field bg-raised px-3 py-2 text-[13px] text-ink-muted">
                        Écart de réconciliation : {formatMoney(situation.customer_position.variance)} — inclut notamment{' '}
                        {formatMoney(situation.reconciliation.unapplied_payments)} de paiement(s) reçu(s) ce mois-ci sur des commandes pas
                        encore facturées (état légitime : le paiement compte dans les encaissements mais n’a pas encore d’obligation
                        facturée à réduire).
                    </p>
                )}
            </section>
        </ApplicationShell>
    );
}

function MetricCard({ label, sublabel, value, href }: { label: string; sublabel: string; value: string | null; href?: string }) {
    const content = (
        <div className="rounded-card border border-line bg-surface p-5 transition-soft hover:border-line-strong">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p>
            <p className="mt-2 text-2xl font-semibold text-ink">{value === null ? '—' : formatMoney(value)}</p>
            <p className="mt-1 text-[12px] text-ink-muted">{sublabel}</p>
        </div>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}

function ReconciliationRow({ label, value }: { label: string; value: string }): ReactNode {
    return (
        <tr>
            <td className="py-1.5 text-ink-muted">{label}</td>
            <td className="py-1.5 text-right text-ink">{formatMoney(value)}</td>
        </tr>
    );
}
