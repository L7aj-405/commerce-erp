import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type PaymentRow = {
    id: number;
    payment_number: string;
    payment_date: string;
    method: string;
    amount: string;
    financial_account: { id: number; name: string; code: string; type: string } | null;
    order: { id: number; order_number: string; customer_name: string | null; customer_company: string | null } | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    payments: { data: PaymentRow[]; links: LinkData[]; total: number };
};

const METHOD_LABELS: Record<string, string> = { cash: 'Espèces', card: 'TPE', bank_transfer: 'Virement', cheque: 'Chèque' };

export default function FinanceEncaissements({ organization, period, periodLabel, storeId, stores, payments }: Props) {
    return (
        <ApplicationShell wide>
            <Head title="Encaissements — Finance" />
            <PageHeader title="Encaissements" description={`${organization.name} · ${periodLabel} · ${payments.total} paiement(s) encaissé(s)`} />
            <FinanceFilters path="/finance/encaissements" period={period} storeId={storeId} stores={stores} />

            <div className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full text-left text-sm">
                    <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-4 py-2.5">N° paiement</th>
                            <th className="px-4 py-2.5">Date</th>
                            <th className="px-4 py-2.5">Mode</th>
                            <th className="px-4 py-2.5">Compte</th>
                            <th className="px-4 py-2.5">Commande</th>
                            <th className="px-4 py-2.5">Client</th>
                            <th className="px-4 py-2.5 text-right">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        {payments.data.map((payment) => (
                            <tr key={payment.id} className="border-t border-line">
                                <td className="px-4 py-2.5 font-medium text-ink">
                                    <Link href={`/payments/${payment.id}`}>{payment.payment_number}</Link>
                                </td>
                                <td className="px-4 py-2.5 text-ink-muted">{payment.payment_date}</td>
                                <td className="px-4 py-2.5">{METHOD_LABELS[payment.method] ?? payment.method}</td>
                                <td className="px-4 py-2.5 text-ink-muted">{payment.financial_account?.name ?? '—'}</td>
                                <td className="px-4 py-2.5">
                                    {payment.order ? <Link href={`/sales/orders/${payment.order.id}`}>{payment.order.order_number}</Link> : '—'}
                                </td>
                                <td className="px-4 py-2.5">{payment.order?.customer_company || payment.order?.customer_name || '—'}</td>
                                <td className="px-4 py-2.5 text-right">{formatMoney(payment.amount)}</td>
                            </tr>
                        ))}
                        {payments.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-ink-faint">
                                    Aucun paiement encaissé sur cette période.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination links={payments.links} />
        </ApplicationShell>
    );
}
