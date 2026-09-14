import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type InvoiceRow = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    customer_name: string | null;
    customer_company: string | null;
    total_incl_tax: string;
    paid_amount: string;
    outstanding: string;
    fully_paid_at: string | null;
    status: 'unpaid' | 'partial' | 'paid';
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    invoices: { data: InvoiceRow[]; links: LinkData[]; total: number };
};

const STATUS_LABELS: Record<string, string> = { paid: 'Soldée', partial: 'Partiellement payée', unpaid: 'Impayée' };

export default function FinanceFacturation({ organization, period, periodLabel, storeId, stores, invoices }: Props) {
    return (
        <ApplicationShell wide>
            <Head title="Facturation — Finance" />
            <PageHeader
                title="Facturation"
                description={`${organization.name} · ${periodLabel} · ${invoices.total} facture(s) émise(s) — la facture en cours (originale non remplacée par une correction)`}
            />
            <FinanceFilters path="/finance/facturation" period={period} storeId={storeId} stores={stores} />

            <div className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full text-left text-sm">
                    <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-4 py-2.5">N° facture</th>
                            <th className="px-4 py-2.5">Date</th>
                            <th className="px-4 py-2.5">Client</th>
                            <th className="px-4 py-2.5 text-right">Total TTC</th>
                            <th className="px-4 py-2.5 text-right">Payé</th>
                            <th className="px-4 py-2.5 text-right">Solde</th>
                            <th className="px-4 py-2.5">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoices.data.map((invoice) => (
                            <tr key={invoice.id} className="border-t border-line">
                                <td className="px-4 py-2.5 font-medium text-ink">
                                    <Link href={`/invoices/${invoice.id}`}>{invoice.invoice_number}</Link>
                                </td>
                                <td className="px-4 py-2.5 text-ink-muted">{invoice.invoice_date}</td>
                                <td className="px-4 py-2.5">{invoice.customer_company || invoice.customer_name || '—'}</td>
                                <td className="px-4 py-2.5 text-right">{formatMoney(invoice.total_incl_tax)}</td>
                                <td className="px-4 py-2.5 text-right text-ink-muted">{formatMoney(invoice.paid_amount)}</td>
                                <td className="px-4 py-2.5 text-right">{formatMoney(invoice.outstanding)}</td>
                                <td className="px-4 py-2.5 text-ink-muted">
                                    {STATUS_LABELS[invoice.status]}
                                    {invoice.status === 'paid' && invoice.fully_paid_at && (
                                        <span className="ml-1 text-[11px] text-ink-faint">({invoice.fully_paid_at})</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {invoices.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-ink-faint">
                                    Aucune facture émise sur cette période.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination links={invoices.links} />
        </ApplicationShell>
    );
}
