import DocBadge from '@/components/ui/DocBadge';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatMoney } from '@/utils/format';
import { invoiceStatusLabel, invoiceStatusTone, label } from '@/utils/labels';
import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Invoice = {
    id: number;
    invoice_number: string | null;
    invoice_date: string;
    customer_name: string | null;
    customer_company: string | null;
    total_incl_tax: string;
    currency_code: string;
    status: string;
    sales_order: { id: number; order_number: string };
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = { invoices: { data: Invoice[]; links: PageLink[] }; filters: { search?: string; status?: string; invoice_date?: string } };

export default function InvoiceIndex({ invoices, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const debounced = useDebouncedValue(search, 250);
    const mounted = useRef(false);

    const apply = (patch: Record<string, string | undefined>) =>
        router.get('/invoices', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;
            return;
        }
        apply({ search: debounced || undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    return (
        <SalesLayout>
            <Head title="Factures" />
            <PageHeader title="Factures" description="Documents commerciaux du magasin actif." />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchInput
                    value={search}
                    onChange={setSearch}
                    searching={search !== (filters.search ?? '')}
                    placeholder="N° facture, commande, client…"
                />
                <FilterSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })}>
                    <option value="">Tous statuts</option>
                    <option value="draft">Brouillon</option>
                    <option value="issued">Émise</option>
                    <option value="cancelled">Annulée</option>
                </FilterSelect>
                <input
                    type="date"
                    value={filters.invoice_date ?? ''}
                    onChange={(e) => apply({ invoice_date: e.target.value || undefined })}
                    className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                />
            </div>

            <div className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full min-w-[760px] text-left text-sm">
                    <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th className="px-4 py-3 font-medium">Facture</th>
                            <th className="px-4 py-3 font-medium">Date</th>
                            <th className="px-4 py-3 font-medium">Commande</th>
                            <th className="px-4 py-3 font-medium">Client</th>
                            <th className="px-4 py-3 font-medium">Statut</th>
                            <th className="px-4 py-3 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {invoices.data.map((invoice) => (
                            <tr
                                key={invoice.id}
                                onClick={() => router.visit(`/invoices/${invoice.id}`)}
                                className="cursor-pointer transition-soft hover:bg-sage/50"
                            >
                                <td className="px-4 py-3 font-medium text-ink">
                                    {invoice.invoice_number ?? 'Brouillon'}
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{formatDate(invoice.invoice_date)}</td>
                                <td className="px-4 py-3 text-ink-muted">{invoice.sales_order.order_number}</td>
                                <td className="px-4 py-3 text-ink">
                                    {invoice.customer_company ?? invoice.customer_name ?? 'Client comptoir'}
                                </td>
                                <td className="px-4 py-3">
                                    <DocBadge tone={invoiceStatusTone(invoice.status)}>
                                        {label(invoiceStatusLabel, invoice.status)}
                                    </DocBadge>
                                </td>
                                <td className="px-4 py-3 text-right font-medium tabular-nums text-ink">
                                    {formatMoney(invoice.total_incl_tax, invoice.currency_code)}
                                </td>
                            </tr>
                        ))}
                        {invoices.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-ink-muted">
                                    Aucune facture ne correspond à ces critères.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination links={invoices.links} />
        </SalesLayout>
    );
}
