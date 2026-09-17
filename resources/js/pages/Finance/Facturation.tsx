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
const STATUS_TONE: Record<string, string> = {
    paid: 'bg-success-soft text-success',
    partial: 'bg-warning-soft text-warning',
    unpaid: 'bg-danger-soft text-danger',
};

export default function FinanceFacturation({ organization, period, periodLabel, storeId, stores, invoices }: Props) {
    return (
        <ApplicationShell wide>
            <Head title="Facturation — Finance" />
            <PageHeader
                title="Facturation"
                description={`${organization.name} · ${periodLabel} · ${invoices.total} facture(s) émise(s) — la facture en cours (originale non remplacée par une correction)`}
            />
            <FinanceFilters path="/finance/facturation" period={period} storeId={storeId} stores={stores} />

            {invoices.data.length === 0 ? (
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
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {invoices.data.map((invoice) => (
                            <li key={invoice.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/invoices/${invoice.id}`} className="block truncate text-sm font-semibold text-ink">
                                            {invoice.invoice_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">{invoice.customer_company || invoice.customer_name || '—'}</p>
                                    </div>
                                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_TONE[invoice.status] ?? 'bg-sage text-ink-muted'}`}>
                                        {STATUS_LABELS[invoice.status]}
                                    </span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Date</dt>
                                        <dd className="text-ink-muted">
                                            {invoice.invoice_date}
                                            {invoice.status === 'paid' && invoice.fully_paid_at && (
                                                <span className="block text-[11px] text-ink-faint">Soldée le {invoice.fully_paid_at}</span>
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Total TTC</dt>
                                        <dd className="font-medium text-ink">{formatMoney(invoice.total_incl_tax)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Payé</dt>
                                        <dd className="text-ink-muted">{formatMoney(invoice.paid_amount)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Solde</dt>
                                        <dd className="font-medium text-ink">{formatMoney(invoice.outstanding)}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={invoices.links} />
        </ApplicationShell>
    );
}
