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
type RefundRow = {
    id: number;
    refund_number: string;
    refund_date: string;
    method: string;
    amount: string;
    reason: string;
    original_payment: { id: number; payment_number: string } | null;
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
    refunds: { data: RefundRow[]; links: LinkData[]; total: number };
};

const METHOD_LABELS: Record<string, string> = { cash: 'Espèces', card: 'TPE', bank_transfer: 'Virement', cheque: 'Chèque' };

export default function FinanceEncaissements({ organization, period, periodLabel, storeId, stores, payments, refunds }: Props) {
    return (
        <ApplicationShell wide>
            <Head title="Encaissements — Finance" />
            <PageHeader title="Encaissements" description={`${organization.name} · ${periodLabel} · ${payments.total} paiement(s) encaissé(s)`} />
            <FinanceFilters path="/finance/encaissements" period={period} storeId={storeId} stores={stores} />

            {payments.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center text-sm text-ink-faint">
                    Aucun paiement encaissé sur cette période.
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
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
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {payments.data.map((payment) => (
                            <li key={payment.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/payments/${payment.id}`} className="block truncate text-sm font-semibold text-ink">
                                            {payment.payment_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">
                                            {payment.order?.customer_company || payment.order?.customer_name || '—'}
                                        </p>
                                    </div>
                                    <p className="shrink-0 text-sm font-semibold text-ink">{formatMoney(payment.amount)}</p>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Date</dt>
                                        <dd className="text-ink-muted">{payment.payment_date}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Mode</dt>
                                        <dd className="text-ink-muted">{METHOD_LABELS[payment.method] ?? payment.method}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Compte</dt>
                                        <dd className="truncate text-ink-muted">{payment.financial_account?.name ?? '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Commande</dt>
                                        <dd className="truncate">
                                            {payment.order ? <Link href={`/sales/orders/${payment.order.id}`}>{payment.order.order_number}</Link> : '—'}
                                        </dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={payments.links} />

            <section className="mt-8">
                <h2 className="mb-3 text-sm font-semibold text-ink">Remboursements · {refunds.total}</h2>
                {refunds.data.length === 0 ? (
                    <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-6 text-center text-sm text-ink-faint">
                        Aucun remboursement enregistré sur cette période.
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-card border border-line bg-surface">
                        <table className="w-full min-w-[820px] text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-2.5">N° remboursement</th>
                                    <th className="px-4 py-2.5">Date</th>
                                    <th className="px-4 py-2.5">Paiement original</th>
                                    <th className="px-4 py-2.5">Mode / compte</th>
                                    <th className="px-4 py-2.5">Commande</th>
                                    <th className="px-4 py-2.5">Motif</th>
                                    <th className="px-4 py-2.5 text-right">Sortie</th>
                                </tr>
                            </thead>
                            <tbody>
                                {refunds.data.map((refund) => (
                                    <tr key={refund.id} className="border-t border-line">
                                        <td className="px-4 py-2.5 font-medium text-ink">{refund.refund_number}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{refund.refund_date}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{refund.original_payment?.payment_number ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{METHOD_LABELS[refund.method] ?? refund.method} · {refund.financial_account?.name ?? '—'}</td>
                                        <td className="px-4 py-2.5">{refund.order ? <Link href={`/sales/orders/${refund.order.id}`}>{refund.order.order_number}</Link> : '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{refund.reason}</td>
                                        <td className="px-4 py-2.5 text-right font-medium text-danger">− {formatMoney(refund.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination links={refunds.links} />
            </section>
        </ApplicationShell>
    );
}
