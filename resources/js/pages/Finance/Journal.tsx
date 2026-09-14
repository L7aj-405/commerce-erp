import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import { DownloadLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type JournalRow = {
    id: number;
    date: string;
    invoice_number: string;
    designation: string;
    total_incl_tax: string;
    customer: string;
    payment_method: string;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    rows: { data: JournalRow[]; links: LinkData[]; total: number };
    can: { export: boolean };
};

export default function FinanceJournal({ organization, period, periodLabel, storeId, stores, rows, can }: Props) {
    const q = new URLSearchParams({ month: period, store_id: storeId ? String(storeId) : '' }).toString();

    return (
        <ApplicationShell wide>
            <Head title="Journal des ventes — Finance" />
            <PageHeader
                title="Journal des ventes"
                description={`${organization.name} · ${periodLabel} · ${rows.total} facture(s)`}
                actions={
                    can.export ? (
                        <DownloadLink href={`/finance/export/xlsx?${q}`} variant="secondary" size="sm">
                            Export XLSX
                        </DownloadLink>
                    ) : undefined
                }
            />
            <FinanceFilters path="/finance/journal" period={period} storeId={storeId} stores={stores} />

            <div className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full text-left text-sm">
                    <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-4 py-2.5">Date</th>
                            <th className="px-4 py-2.5">N° facture</th>
                            <th className="px-4 py-2.5">Désignation</th>
                            <th className="px-4 py-2.5">Client</th>
                            <th className="px-4 py-2.5 text-right">Total TTC</th>
                            <th className="px-4 py-2.5">Mode d’encaissement</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.data.map((row) => (
                            <tr key={row.id} className="border-t border-line">
                                <td className="px-4 py-2.5 text-ink-muted">{row.date}</td>
                                <td className="px-4 py-2.5 font-medium text-ink">
                                    <Link href={`/invoices/${row.id}`}>{row.invoice_number}</Link>
                                </td>
                                <td className="px-4 py-2.5">{row.designation}</td>
                                <td className="px-4 py-2.5">{row.customer}</td>
                                <td className="px-4 py-2.5 text-right">{formatMoney(row.total_incl_tax)}</td>
                                <td className="px-4 py-2.5 text-ink-muted">{row.payment_method}</td>
                            </tr>
                        ))}
                        {rows.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-ink-faint">
                                    Aucune facture émise sur cette période.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination links={rows.links} />
        </ApplicationShell>
    );
}
