import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import SoldLines, { type SoldLine } from '@/components/finance/SoldLines';
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
    nature_label: 'Avance' | 'Règlement facture';
    sales_order_id: number;
    order_number: string;
    lines: SoldLine[];
    customer: string;
    method_label: string;
    amount: string;
    status_label: string;
};
type PaymentLine = Pick<CaEncaisseRow, 'id' | 'payment_number' | 'payment_date' | 'method_label' | 'amount'>;
type CaEncaisseGroup = Omit<CaEncaisseRow, 'id' | 'payment_number' | 'payment_date' | 'method_label' | 'amount' | 'status_label'> & {
    id: string;
    amount: string;
    status_label: string;
    payments: PaymentLine[];
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
    'Paiement comptant': 'bg-success-soft text-success',
    'Paiement complété': 'bg-success-soft text-success',
    'Paiement partiel': 'bg-warning-soft text-warning',
    'Solde / Reliquat': 'bg-warning-soft text-warning',
};

const completedStatuses = new Set(['Paiement comptant', 'Solde / Reliquat']);

function toTenThousandths(value: string): bigint {
    const clean = value.trim();
    const sign = clean.startsWith('-') ? -1n : 1n;
    const [whole, fraction = ''] = clean.replace(/^-/, '').split('.');

    return sign * (BigInt(whole || '0') * 10000n + BigInt(fraction.padEnd(4, '0').slice(0, 4)));
}

function fromTenThousandths(value: bigint): string {
    const sign = value < 0n ? '-' : '';
    const absolute = value < 0n ? -value : value;
    const whole = absolute / 10000n;
    const fraction = String(absolute % 10000n).padStart(4, '0');

    return `${sign}${whole}.${fraction}`;
}

function groupRows(rows: CaEncaisseRow[]): CaEncaisseGroup[] {
    const groups = new Map<string, CaEncaisseGroup>();

    rows.forEach((row) => {
        const key = row.reference_type === 'invoice' && row.invoice_id ? `invoice:${row.invoice_id}` : `order:${row.sales_order_id}`;
        const existing = groups.get(key);
        const payment = {
            id: row.id,
            payment_number: row.payment_number,
            payment_date: row.payment_date,
            method_label: row.method_label,
            amount: row.amount,
        };

        if (!existing) {
            groups.set(key, {
                id: key,
                sale_date: row.sale_date,
                reference: row.reference,
                reference_type: row.reference_type,
                invoice_id: row.invoice_id,
                nature_label: row.nature_label,
                sales_order_id: row.sales_order_id,
                order_number: row.order_number,
                lines: row.lines,
                customer: row.customer,
                amount: row.amount,
                status_label: row.status_label,
                payments: [payment],
            });
            return;
        }

        existing.amount = fromTenThousandths(toTenThousandths(existing.amount) + toTenThousandths(row.amount));
        existing.payments.push(payment);
        if (completedStatuses.has(row.status_label)) {
            existing.status_label = 'Paiement complété';
        }
    });

    return Array.from(groups.values()).map((group) => ({
        ...group,
        status_label: completedStatuses.has(group.status_label) ? 'Paiement complété' : group.status_label,
    }));
}

export default function FinanceCaEncaisse({ organization, period, periodLabel, storeId, stores, total, rows, can }: Props) {
    const q = new URLSearchParams({ month: period, store_id: storeId ? String(storeId) : '' }).toString();
    const groups = groupRows(rows.data);

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
                            packageHref={`/finance/ca-encaisse/export/full-package?${q}`}
                            zipFilename={`Factures_${period}.zip`}
                            packageFilename={`Package_CA_${period}.zip`}
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
                                    <th className="px-4 py-2.5">Nature</th>
                                    <th className="px-4 py-2.5">Désignation</th>
                                    <th className="px-4 py-2.5">Client</th>
                                    <th className="px-4 py-2.5">Mode d’encaissement</th>
                                    <th className="px-4 py-2.5 text-right">Montant encaissé</th>
                                    <th className="px-4 py-2.5">Statut paiement</th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.map((group) => (
                                    <tr key={group.id} className="border-t-2 border-line-strong align-top">
                                        <td className="px-4 py-2.5">
                                            <div className="divide-y divide-line">
                                                {group.payments.map((payment) => (
                                                    <div key={payment.id} className="py-1 first:pt-0 last:pb-0">
                                                        <p className="font-medium text-ink">{payment.payment_number}</p>
                                                        <p className="text-[11px] text-ink-faint">Montant partiel {formatMoney(payment.amount)}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2.5 whitespace-nowrap text-ink-muted">{formatDate(group.sale_date)}</td>
                                        <td className="px-4 py-2.5">
                                            <div className="divide-y divide-line">
                                                {group.payments.map((payment) => (
                                                    <div key={payment.id} className="py-1 first:pt-0 last:pb-0 whitespace-nowrap text-ink-muted">{formatDate(payment.payment_date)}</div>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {group.reference_type === 'invoice' && group.invoice_id ? (
                                                <Link href={`/invoices/${group.invoice_id}`}>{group.reference}</Link>
                                            ) : (
                                                <Link href={`/sales/orders/${group.sales_order_id}`}>{group.reference}</Link>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <span className="inline-flex rounded-full bg-raised px-2.5 py-1 text-[11px] font-semibold text-ink-muted">
                                                {group.nature_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <SoldLines lines={group.lines} />
                                        </td>
                                        <td className="px-4 py-2.5">{group.customer}</td>
                                        <td className="px-4 py-2.5">
                                            <div className="divide-y divide-line">
                                                {group.payments.map((payment) => (
                                                    <div key={payment.id} className="py-1 first:pt-0 last:pb-0 text-ink-muted">{payment.method_label}</div>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2.5 text-right">
                                            <p className="tabular-nums font-semibold text-ink">{formatMoney(group.amount)}</p>
                                            {group.payments.length > 1 && <p className="text-[11px] text-ink-faint">Montant encaissé</p>}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${STATUS_STYLES[group.status_label] ?? 'bg-raised text-ink-muted'}`}>
                                                {group.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {groups.map((group) => (
                            <li key={group.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <p className="min-w-0 truncate text-sm font-semibold text-ink">{group.reference}</p>
                                    <span className={`shrink-0 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${STATUS_STYLES[group.status_label] ?? 'bg-raised text-ink-muted'}`}>
                                        {group.status_label}
                                    </span>
                                </div>
                                <div className="mt-2.5">
                                    <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">Désignation</p>
                                    <div className="mt-1">
                                        <SoldLines lines={group.lines} />
                                    </div>
                                </div>
                                <div className="mt-3 divide-y divide-line rounded-field bg-raised px-3">
                                    {group.payments.map((payment) => (
                                        <div key={payment.id} className="grid grid-cols-2 gap-2 py-2 text-[13px]">
                                            <div>
                                                <p className="font-medium text-ink">{payment.payment_number}</p>
                                                <p className="text-ink-faint">{formatDate(payment.payment_date)} · {payment.method_label}</p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-ink-faint">Montant partiel</p>
                                                <p className="font-semibold tabular-nums text-ink">{formatMoney(payment.amount)}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Client</dt>
                                        <dd className="truncate text-ink-muted">{group.customer}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Réf. facture/commande</dt>
                                        <dd className="truncate">
                                            {group.reference_type === 'invoice' && group.invoice_id ? (
                                                <Link href={`/invoices/${group.invoice_id}`}>{group.reference}</Link>
                                            ) : (
                                                <Link href={`/sales/orders/${group.sales_order_id}`}>{group.reference}</Link>
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Nature</dt>
                                        <dd className="truncate text-ink-muted">{group.nature_label}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Date de vente</dt>
                                        <dd className="text-ink-muted">{formatDate(group.sale_date)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Montant encaissé</dt>
                                        <dd className="tabular-nums font-semibold text-ink">{formatMoney(group.amount)}</dd>
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
    packageHref,
    zipFilename,
    packageFilename,
}: {
    xlsxHref: string;
    pdfHrefBase: string;
    zipHref: string;
    packageHref: string;
    zipFilename: string;
    packageFilename: string;
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

    const downloadZip = async (href: string, filename: string, errorMessage: string) => {
        if (zipBusy) return;
        setZipBusy(true);
        try {
            const response = await fetch(href, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                const data = (await response.json().catch(() => ({}))) as { message?: string };
                toast.error(data.message ?? errorMessage);
                return;
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch {
            toast.error(errorMessage);
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
                        onClick={() => downloadZip(zipHref, zipFilename, 'Échec de l’export des factures.')}
                        disabled={zipBusy}
                        aria-busy={zipBusy || undefined}
                        className="flex min-h-10 w-full items-center gap-2 rounded-field px-3 py-2.5 text-left text-[13px] text-ink transition-soft hover:bg-sage disabled:opacity-60"
                    >
                        {zipBusy && <Spinner size="xs" />}
                        {zipBusy ? 'Génération du ZIP…' : 'Factures (.ZIP)'}
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => downloadZip(packageHref, packageFilename, 'Échec de l’export du package complet.')}
                        disabled={zipBusy}
                        aria-busy={zipBusy || undefined}
                        className="flex min-h-10 w-full items-center gap-2 rounded-field px-3 py-2.5 text-left text-[13px] text-ink transition-soft hover:bg-sage disabled:opacity-60"
                    >
                        {zipBusy && <Spinner size="xs" />}
                        Package complet
                    </button>
                </div>
            )}
        </div>
    );
}
