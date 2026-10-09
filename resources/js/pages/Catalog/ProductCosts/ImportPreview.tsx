import { Button, ButtonLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import CatalogLayout from '@/layouts/CatalogLayout';
import { formatMoney } from '@/utils/format';
import { Head, useForm } from '@inertiajs/react';

type Import = { id: number; original_file_name: string; status: string; total_rows: number; ready_count: number; unchanged_count: number; invalid_count: number; not_found_count: number; duplicate_count: number; skipped_count: number; updated_count: number; created_by: { name: string } };
type Row = { id: number; row_number: number; product_name: string | null; variant_label: string | null; sku: string | null; current_purchase_price: string | null; new_purchase_price: string | null; status: string; message: string | null };
type Props = { import: Import; rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] } };

const labels: Record<string, string> = { ready: 'Prêt', unchanged: 'Inchangé', invalid: 'Invalide', not_found: 'Introuvable', duplicate: 'Doublon', skipped: 'Ignoré', updated: 'Mis à jour' };
const colors: Record<string, string> = { ready: 'bg-success-soft text-success', updated: 'bg-success-soft text-success', unchanged: 'bg-raised text-ink-muted', invalid: 'bg-danger-soft text-danger', not_found: 'bg-danger-soft text-danger', duplicate: 'bg-warning-soft text-warning', skipped: 'bg-raised text-ink-muted' };

export default function ProductCostImportPreview({ import: productImport, rows }: Props) {
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const confirm = () => form.post(`/catalog/purchase-prices/imports/${productImport.id}/confirm`);
    const completed = productImport.status === 'completed';
    return <CatalogLayout>
        <Head title="Prévisualisation des prix d'achat" />
        <PageHeader title="Prévisualisation de l’import" description={`${productImport.original_file_name} · ${productImport.total_rows} lignes analysées`} actions={<ButtonLink href="/catalog/purchase-prices" variant="secondary">Retour aux prix d'achat</ButtonLink>} />
        <section className="mb-6 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <Count label="Prêtes" value={productImport.ready_count} /><Count label="Inchangées" value={productImport.unchanged_count} /><Count label="Introuvables" value={productImport.not_found_count} /><Count label="Invalides" value={productImport.invalid_count} /><Count label="Doublons" value={productImport.duplicate_count} /><Count label="Ignorées" value={productImport.skipped_count} />
        </section>
        <section className="mb-6 flex flex-col gap-3 rounded-card border border-line bg-surface p-5 shadow-soft sm:flex-row sm:items-center sm:justify-between">
            <div><p className="font-semibold text-ink">{completed ? `${productImport.updated_count} prix mis à jour` : `${productImport.ready_count} prix prêts à être appliqués`}</p><p className="mt-1 text-sm text-ink-muted">Seules les lignes « Prêt » modifieront le prix d'achat HT. Les autres données du catalogue resteront intactes.</p>{errors.import && <p className="mt-2 text-sm text-danger">{errors.import}</p>}</div>
            {!completed && <Button type="button" disabled={productImport.ready_count === 0 || form.processing} onClick={confirm}>{form.processing ? 'Application…' : 'Confirmer les modifications'}</Button>}
        </section>
        <div className="overflow-x-auto rounded-card border border-line bg-surface shadow-soft">
            <table className="w-full min-w-[850px] text-left text-sm"><thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint"><tr><th className="px-4 py-3">Ligne</th><th className="px-4 py-3">Produit / variante</th><th className="px-4 py-3 text-right">Prix actuel HT</th><th className="px-4 py-3 text-right">Nouveau prix HT</th><th className="px-4 py-3">Statut</th><th className="px-4 py-3">Détail</th></tr></thead>
            <tbody className="divide-y divide-line">{rows.data.map((row) => <tr key={row.id}><td className="px-4 py-3 text-ink-faint">{row.row_number}</td><td className="px-4 py-3"><p className="font-semibold">{row.product_name || 'Produit non identifié'}</p><p className="text-xs text-ink-muted">{row.variant_label || 'Variante principale'}{row.sku ? ` · ${row.sku}` : ''}</p></td><td className="px-4 py-3 text-right tabular-nums">{row.current_purchase_price === null ? '—' : formatMoney(row.current_purchase_price)}</td><td className="px-4 py-3 text-right font-semibold tabular-nums">{row.new_purchase_price === null ? '—' : formatMoney(row.new_purchase_price)}</td><td className="px-4 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${colors[row.status] ?? 'bg-raised'}`}>{labels[row.status] ?? row.status}</span></td><td className="max-w-sm px-4 py-3 text-ink-muted">{row.message || '—'}</td></tr>)}</tbody></table>
        </div>
        <Pagination links={rows.links} />
    </CatalogLayout>;
}

function Count({ label, value }: { label: string; value: number }) {
    return <div className="rounded-card border border-line bg-surface p-4 shadow-soft"><p className="text-xs uppercase tracking-wide text-ink-faint">{label}</p><p className="mt-1 text-xl font-semibold tabular-nums">{value.toLocaleString('fr-FR')}</p></div>;
}
