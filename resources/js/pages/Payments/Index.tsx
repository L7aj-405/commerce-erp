import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import PaymentsLayout from '@/layouts/PaymentsLayout';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

type Account = { id: number; name: string; code: string };
type Payment = {
    id: number;
    payment_number: string;
    method: string;
    status: string;
    amount: string;
    currency_code: string;
    payment_date: string;
    reference: string | null;
    financial_account: Account | null;
    allocations: { sales_order: { id: number; order_number: string; customer_name: string | null; customer_company: string | null } }[];
    received_by: { name: string } | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = { payments: { data: Payment[]; links: PageLink[] }; accounts: Account[]; filters: Record<string, string | number | undefined> };

export default function PaymentIndex({ payments, accounts, filters }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget).entries());
        router.get('/payments', data, { preserveState: true, replace: true });
    };

    return (
        <PaymentsLayout>
            <Head title="Paiements" />
            <PageHeader
                title="Paiements"
                description="Registre des encaissements postés et annulés pour le magasin actif."
            />

            <form onSubmit={submit} className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                <div className="grid gap-3 md:grid-cols-6">
                    <label className="block md:col-span-2">
                        <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-faint">Recherche</span>
                        <input
                            name="search"
                            defaultValue={String(filters.search ?? '')}
                            placeholder="Paiement, commande, client"
                            className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                        />
                    </label>
                    <Field label="Date">
                        <input
                            name="date"
                            type="date"
                            defaultValue={String(filters.date ?? '')}
                            className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                        />
                    </Field>
                    <Field label="Mode">
                        <select name="method" defaultValue={String(filters.method ?? '')} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                            <option value="">Tous les modes</option>
                            <option value="cash">Espèces</option>
                            <option value="card">Carte / TPE</option>
                            <option value="bank_transfer">Virement</option>
                            <option value="cheque">Chèque</option>
                        </select>
                    </Field>
                    <Field label="Statut">
                        <select name="status" defaultValue={String(filters.status ?? '')} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                            <option value="">Tous les statuts</option>
                            <option value="posted">Posté</option>
                            <option value="reversed">Annulé</option>
                        </select>
                    </Field>
                    <Field label="Compte">
                        <select name="account" defaultValue={String(filters.account ?? '')} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                            <option value="">Tous les comptes</option>
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.code} · {account.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                <div className="mt-4 flex justify-end">
                    <button className="rounded-field bg-ink px-4 py-2 text-sm font-semibold text-white transition-soft hover:bg-ink-muted">Filtrer</button>
                </div>
            </form>

            {payments.data.length === 0 ? (
                <EmptyState title="Aucun paiement" description="Aucun encaissement ne correspond aux filtres sélectionnés." />
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface shadow-soft md:block">
                        <table className="w-full min-w-[980px] text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-3">N° paiement</th>
                                    <th className="px-4 py-3">Date</th>
                                    <th className="px-4 py-3">Client</th>
                                    <th className="px-4 py-3">Commande / facture</th>
                                    <th className="px-4 py-3">Mode</th>
                                    <th className="px-4 py-3">Compte financier</th>
                                    <th className="px-4 py-3">Statut</th>
                                    <th className="px-4 py-3 text-right">Montant</th>
                                    <th className="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {payments.data.map((payment) => {
                                    const order = payment.allocations[0]?.sales_order;
                                    const customer = order?.customer_name ?? order?.customer_company ?? 'Client comptoir';

                                    return (
                                        <tr key={payment.id} className="transition-soft hover:bg-raised/70">
                                            <td className="px-4 py-3">
                                                <Link href={`/payments/${payment.id}`} className="font-semibold text-ink underline-offset-2 hover:underline">
                                                    {payment.payment_number}
                                                </Link>
                                                {payment.reference && <p className="mt-1 text-xs text-ink-faint">{payment.reference}</p>}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-ink-muted">{formatDate(payment.payment_date)}</td>
                                            <td className="px-4 py-3 font-medium text-ink">{customer}</td>
                                            <td className="px-4 py-3">
                                                {order ? (
                                                    <Link href={`/sales/orders/${order.id}`} className="font-medium text-ink underline-offset-2 hover:underline">
                                                        {order.order_number}
                                                    </Link>
                                                ) : (
                                                    <span className="text-ink-faint">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <MethodBadge method={payment.method} />
                                            </td>
                                            <td className="px-4 py-3 text-ink-muted">{payment.financial_account?.name ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={payment.status} />
                                            </td>
                                            <td className="px-4 py-3 text-right font-semibold tabular-nums text-ink">{formatMoney(payment.amount, payment.currency_code)}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Link href={`/payments/${payment.id}`} className="rounded-field px-3 py-2 text-sm font-medium text-ink transition-soft hover:bg-sage">
                                                    Ouvrir
                                                </Link>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden">
                        {payments.data.map((payment) => {
                            const order = payment.allocations[0]?.sales_order;
                            const customer = order?.customer_name ?? order?.customer_company ?? 'Client comptoir';

                            return (
                                <li key={payment.id} className="rounded-card border border-line bg-surface p-4 shadow-soft">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <Link href={`/payments/${payment.id}`} className="block truncate font-semibold text-ink underline-offset-2 hover:underline">
                                                {payment.payment_number}
                                            </Link>
                                            <p className="truncate text-sm text-ink-muted">{customer}</p>
                                        </div>
                                        <p className="shrink-0 font-semibold tabular-nums text-ink">{formatMoney(payment.amount, payment.currency_code)}</p>
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <MethodBadge method={payment.method} />
                                        <StatusBadge status={payment.status} />
                                    </div>
                                    <dl className="mt-4 grid grid-cols-2 gap-x-3 gap-y-2 text-[13px]">
                                        <Info label="Date" value={formatDate(payment.payment_date)} />
                                        <Info label="Compte" value={payment.financial_account?.name ?? '—'} />
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">Commande</dt>
                                            <dd className="truncate text-ink-muted">
                                                {order ? (
                                                    <Link href={`/sales/orders/${order.id}`} className="underline-offset-2 hover:underline">
                                                        {order.order_number}
                                                    </Link>
                                                ) : (
                                                    '—'
                                                )}
                                            </dd>
                                        </div>
                                        <Info label="Reçu par" value={payment.received_by?.name ?? '—'} />
                                    </dl>
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}

            <Pagination links={payments.links} />
        </PaymentsLayout>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</span>
            {children}
        </label>
    );
}

function MethodBadge({ method }: { method: string }) {
    const labels: Record<string, string> = {
        cash: 'Espèces',
        card: 'Carte / TPE',
        bank_transfer: 'Virement',
        cheque: 'Chèque',
    };

    return <span className="inline-flex rounded-full bg-sage px-2.5 py-1 text-xs font-semibold text-ink">{labels[method] ?? method.replaceAll('_', ' ')}</span>;
}

function StatusBadge({ status }: { status: string }) {
    const styles: Record<string, string> = {
        posted: 'bg-success-soft text-success',
        reversed: 'bg-danger-soft text-danger',
    };
    const labels: Record<string, string> = {
        posted: 'Posté',
        reversed: 'Annulé',
    };

    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${styles[status] ?? 'bg-raised text-ink-muted'}`}>{labels[status] ?? status.replaceAll('_', ' ')}</span>;
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-ink-faint">{label}</dt>
            <dd className="truncate text-ink-muted">{value}</dd>
        </div>
    );
}
