import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
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
};

export default function FinanceJournal({ organization, period, periodLabel, storeId, stores, rows }: Props) {
    return (
        <ApplicationShell wide>
            <Head title="Journal des ventes — Finance" />
            <PageHeader title="Journal des ventes" description={`${organization.name} · ${periodLabel} · ${rows.total} facture(s)`} />
            <FinanceFilters path="/finance/journal" period={period} storeId={storeId} stores={stores} />

            {rows.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center text-sm text-ink-faint">
                    Aucune facture émise sur cette période.
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
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
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {rows.data.map((row) => (
                            <li key={row.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/invoices/${row.id}`} className="block truncate text-sm font-semibold text-ink">
                                            {row.invoice_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">{row.designation}</p>
                                    </div>
                                    <p className="shrink-0 text-sm font-semibold text-ink">{formatMoney(row.total_incl_tax)}</p>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Date</dt>
                                        <dd className="text-ink-muted">{row.date}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Client</dt>
                                        <dd className="truncate text-ink-muted">{row.customer}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Mode</dt>
                                        <dd className="text-ink-muted">{row.payment_method}</dd>
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
