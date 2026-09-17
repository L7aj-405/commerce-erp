import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import { DownloadLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
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
                        <div className="flex gap-2">
                            <DownloadLink href={`/finance/ca-encaisse/export/xlsx?${q}`} variant="secondary" size="sm">
                                Export XLSX
                            </DownloadLink>
                            <PdfExportMenu baseHref={`/finance/ca-encaisse/export/pdf?${q}`} />
                        </div>
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
 * Compact PDF export menu: pick Paysage (default — CA encaissé has 9
 * columns and reads far better wide) or Portrait, then download. A plain
 * `<a>` for the actual download — never Inertia's `<Link>`, which would
 * intercept the click and try to render the binary PDF response as a page
 * instead of letting the browser download it.
 */
function PdfExportMenu({ baseHref }: { baseHref: string }) {
    const [open, setOpen] = useState(false);
    const [orientation, setOrientation] = useState<'landscape' | 'portrait'>('landscape');
    const containerRef = useRef<HTMLDivElement>(null);

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

    const downloadHref = `${baseHref}&orientation=${orientation}`;
    const optionClass = (value: 'landscape' | 'portrait') =>
        `flex-1 rounded-field px-2.5 py-1.5 text-xs font-medium transition-soft ${
            orientation === value ? 'bg-primary text-primary-fg' : 'border border-line-strong text-ink-muted hover:bg-raised'
        }`;

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                aria-haspopup="true"
                aria-expanded={open}
                onClick={() => setOpen((current) => !current)}
                className="inline-flex min-h-8 items-center justify-center gap-1.5 rounded-field border border-line-strong bg-surface px-3 text-xs font-medium text-ink transition-soft hover:bg-raised"
            >
                Format PDF
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="m6 9 6 6 6-6" /></svg>
            </button>

            {open && (
                <div className="absolute right-0 top-full z-20 mt-1.5 w-52 max-w-[calc(100vw-1.5rem)] rounded-field border border-line bg-surface p-3 shadow-pop">
                    <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-faint">Orientation</p>
                    <div className="flex gap-1.5">
                        <button type="button" onClick={() => setOrientation('landscape')} className={optionClass('landscape')}>
                            Paysage
                        </button>
                        <button type="button" onClick={() => setOrientation('portrait')} className={optionClass('portrait')}>
                            Portrait
                        </button>
                    </div>
                    <a
                        href={downloadHref}
                        onClick={() => setOpen(false)}
                        className="mt-3 flex min-h-8 items-center justify-center rounded-field bg-primary px-3 text-xs font-semibold text-primary-fg transition-soft hover:bg-primary-hover"
                    >
                        Télécharger
                    </a>
                </div>
            )}
        </div>
    );
}
