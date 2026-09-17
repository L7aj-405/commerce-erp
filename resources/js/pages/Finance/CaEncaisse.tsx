import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { Spinner } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/toast';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type CaEncaisseRow = {
    id: number;
    payment_number: string;
    sale_date: string;
    payment_date: string;
    reference: string;
    reference_type: 'invoice' | 'order';
    invoice_id: number | null;
    sales_order_id: number;
    order_number: string;
    designation: string;
    customer: string;
    method_label: string;
    amount: string;
    status_label: string;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    total: string;
    rows: { data: CaEncaisseRow[]; links: LinkData[]; total: number };
    can: { export: boolean };
};

const STATUS_STYLES: Record<string, string> = {
    'Avance sur commande': 'bg-sage text-ink',
    'Paiement comptant': 'bg-success-soft text-success',
    'Paiement partiel': 'bg-warning-soft text-warning',
    'Solde / Reliquat': 'bg-warning-soft text-warning',
};

export default function FinanceCaEncaisse({ organization, period, periodLabel, storeId, stores, total, rows, can }: Props) {
    const q = new URLSearchParams({ month: period, store_id: storeId ? String(storeId) : '' }).toString();

    return (
        <ApplicationShell wide>
            <Head title="CA encaissé — Finance" />
            <PageHeader
                title="CA encaissé"
                description={`${organization.name} · ${periodLabel} · Encaissements réellement reçus pendant la période`}
                actions={
                    can.export ? (
                        <ExportMenu
                            xlsxHref={`/finance/ca-encaisse/export/xlsx?${q}`}
                            pdfHrefBase={`/finance/ca-encaisse/export/pdf?${q}`}
                            zipHref={`/finance/ca-encaisse/export/invoices-zip?${q}`}
                            zipFilename={`Factures_${period}.zip`}
                        />
                    ) : undefined
                }
            />
            <FinanceFilters path="/finance/ca-encaisse" period={period} storeId={storeId} stores={stores} />

            <div className="mb-4 rounded-card border border-line bg-surface p-5">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">CA encaissé du mois</p>
                <p className="mt-2 text-2xl font-semibold text-ink">{formatMoney(total)}</p>
            </div>

            {rows.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center text-sm text-ink-faint">
                    Aucun encaissement sur cette période.
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-2.5">N° paiement</th>
                                    <th className="px-4 py-2.5">Date de vente</th>
                                    <th className="px-4 py-2.5">Date de paiement</th>
                                    <th className="px-4 py-2.5">N° facture / commande</th>
                                    <th className="px-4 py-2.5">Désignation</th>
                                    <th className="px-4 py-2.5">Client</th>
                                    <th className="px-4 py-2.5">Mode d’encaissement</th>
                                    <th className="px-4 py-2.5 text-right">Montant encaissé</th>
                                    <th className="px-4 py-2.5">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.data.map((row) => (
                                    <tr key={row.id} className="border-t border-line">
                                        <td className="px-4 py-2.5 font-medium text-ink">{row.payment_number}</td>
                                        <td className="px-4 py-2.5 whitespace-nowrap text-ink-muted">{formatDate(row.sale_date)}</td>
                                        <td className="px-4 py-2.5 whitespace-nowrap text-ink-muted">{formatDate(row.payment_date)}</td>
                                        <td className="px-4 py-2.5">
                                            {row.reference_type === 'invoice' && row.invoice_id ? (
                                                <Link href={`/invoices/${row.invoice_id}`}>{row.reference}</Link>
                                            ) : (
                                                <Link href={`/sales/orders/${row.sales_order_id}`}>{row.reference}</Link>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5">{row.designation}</td>
                                        <td className="px-4 py-2.5">{row.customer}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{row.method_label}</td>
                                        <td className="px-4 py-2.5 text-right tabular-nums">{formatMoney(row.amount)}</td>
                                        <td className="px-4 py-2.5">
                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${STATUS_STYLES[row.status_label] ?? 'bg-raised text-ink-muted'}`}>
                                                {row.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {rows.data.map((row) => (
                            <li key={row.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-ink">{row.payment_number}</p>
                                        <p className="truncate text-[13px] text-ink-muted">{row.designation}</p>
                                    </div>
                                    <span className={`shrink-0 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${STATUS_STYLES[row.status_label] ?? 'bg-raised text-ink-muted'}`}>
                                        {row.status_label}
                                    </span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Client</dt>
                                        <dd className="truncate text-ink-muted">{row.customer}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Réf. facture/commande</dt>
                                        <dd className="truncate">
                                            {row.reference_type === 'invoice' && row.invoice_id ? (
                                                <Link href={`/invoices/${row.invoice_id}`}>{row.reference}</Link>
                                            ) : (
                                                <Link href={`/sales/orders/${row.sales_order_id}`}>{row.reference}</Link>
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Date de vente</dt>
                                        <dd className="text-ink-muted">{formatDate(row.sale_date)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Date de paiement</dt>
                                        <dd className="text-ink-muted">{formatDate(row.payment_date)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Mode</dt>
                                        <dd className="text-ink-muted">{row.method_label}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Montant encaissé</dt>
                                        <dd className="tabular-nums font-semibold text-ink">{formatMoney(row.amount)}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={rows.links} />
        </ApplicationShell>
    );
}

/**
 * Unified "Exporter" menu — Excel, the PDF report (either orientation), and
 * the invoice ZIP — replacing what used to be two separate action buttons.
 * A single dropdown keeps the header action area from growing a third
 * button that would crowd the mobile layout (see the app-wide responsive
 * pass): plain `<a>` downloads for Excel/PDF (never Inertia's `<Link>`,
 * which would try to render the binary response as a page), and a
 * fetch-then-save flow for the ZIP so a "no invoices"/oversized-selection
 * error can be shown as a message instead of downloading a corrupt file.
 */
function ExportMenu({
    xlsxHref,
    pdfHrefBase,
    zipHref,
    zipFilename,
}: {
    xlsxHref: string;
    pdfHrefBase: string;
    zipHref: string;
    zipFilename: string;
}) {
    const [open, setOpen] = useState(false);
    const [zipBusy, setZipBusy] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const toast = useToast();

    useEffect(() => {
        if (!open) return;
        const onClickOutside = (event: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) setOpen(false);
        };
        const onEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onClickOutside);
        window.addEventListener('keydown', onEscape);
        return () => {
            document.removeEventListener('mousedown', onClickOutside);
            window.removeEventListener('keydown', onEscape);
        };
    }, [open]);

    const itemClass = 'block rounded-field px-3 py-2.5 text-[13px] text-ink transition-soft hover:bg-sage';

    const downloadZip = async () => {
        if (zipBusy) return;
        setZipBusy(true);
        try {
            const response = await fetch(zipHref, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                const data = (await response.json().catch(() => ({}))) as { message?: string };
                toast.error(data.message ?? 'Échec de l’export des factures.');
                return;
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = zipFilename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch {
            toast.error('Échec de l’export des factures.');
        } finally {
            setZipBusy(false);
            setOpen(false);
        }
    };

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                aria-haspopup="true"
                aria-expanded={open}
                onClick={() => setOpen((current) => !current)}
                className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-field border border-line-strong bg-surface px-3.5 text-sm font-medium text-ink transition-soft hover:bg-raised"
            >
                Exporter
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="m6 9 6 6 6-6" /></svg>
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute right-0 top-full z-20 mt-1.5 w-64 max-w-[calc(100vw-1.5rem)] rounded-field border border-line bg-surface p-1.5 shadow-pop"
                >
                    <a href={xlsxHref} role="menuitem" onClick={() => setOpen(false)} className={itemClass}>
                        Excel (.xlsx)
                    </a>
                    <a href={`${pdfHrefBase}&orientation=landscape`} role="menuitem" onClick={() => setOpen(false)} className={itemClass}>
                        Rapport PDF (Paysage)
                    </a>
                    <a href={`${pdfHrefBase}&orientation=portrait`} role="menuitem" onClick={() => setOpen(false)} className={itemClass}>
                        Rapport PDF (Portrait)
                    </a>
                    <div className="my-1 border-t border-line" />
                    <button
                        type="button"
                        role="menuitem"
                        onClick={downloadZip}
                        disabled={zipBusy}
                        aria-busy={zipBusy || undefined}
                        className="flex min-h-10 w-full items-center gap-2 rounded-field px-3 py-2.5 text-left text-[13px] text-ink transition-soft hover:bg-sage disabled:opacity-60"
                    >
                        {zipBusy && <Spinner size="xs" />}
                        {zipBusy ? 'Génération du ZIP…' : 'Factures (.ZIP)'}
                    </button>
                </div>
            )}
        </div>
    );
}
