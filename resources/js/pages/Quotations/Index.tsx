import DocBadge from '@/components/ui/DocBadge';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatMoney } from '@/utils/format';
import { label, quotationStatusLabel, quotationStatusTone } from '@/utils/labels';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Quotation = {
    id: number;
    quotation_number: string | null;
    quotation_date: string;
    valid_until: string | null;
    customer_name: string | null;
    customer_company: string | null;
    total_incl_tax: string;
    currency_code: string;
    status: string;
    created_by: { name: string } | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    quotations: { data: Quotation[]; links: PageLink[] };
    filters: { search?: string; status?: string; quotation_date?: string; validity?: string };
};

export default function QuotationIndex({ quotations, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const debounced = useDebouncedValue(search, 250);
    const mounted = useRef(false);

    const apply = (patch: Record<string, string | undefined>) =>
        router.get('/quotations', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;
            return;
        }
        apply({ search: debounced || undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const expired = (q: Quotation) =>
        q.status === 'issued' && q.valid_until !== null && new Date(q.valid_until) < new Date(new Date().toDateString());

    return (
        <SalesLayout>
            <Head title="Devis" />
            <PageHeader
                title="Devis"
                description="Propositions commerciales du magasin actif. Un devis n’impacte ni le stock ni la comptabilité."
                actions={
                    <Link
                        href="/quotations/create"
                        className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                    >
                        Nouveau devis
                    </Link>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchInput value={search} onChange={setSearch} searching={search !== (filters.search ?? '')} placeholder="N° devis, client, téléphone, email…" />
                <FilterSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })}>
                    <option value="">Tous statuts</option>
                    <option value="draft">Brouillon</option>
                    <option value="issued">Émis</option>
                    <option value="accepted">Accepté</option>
                    <option value="rejected">Refusé</option>
                    <option value="expired">Expiré</option>
                    <option value="converted">Transformé</option>
                    <option value="superseded">Remplacé</option>
                </FilterSelect>
                <FilterSelect value={filters.validity ?? ''} onChange={(e) => apply({ validity: e.target.value || undefined })}>
                    <option value="">Toute validité</option>
                    <option value="valid">En cours de validité</option>
                    <option value="expired">Échu</option>
                </FilterSelect>
                <input
                    type="date"
                    value={filters.quotation_date ?? ''}
                    onChange={(e) => apply({ quotation_date: e.target.value || undefined })}
                    className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                />
            </div>

            {quotations.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-10 text-center text-sm text-ink-muted">
                    Aucun devis ne correspond à ces critères.
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="w-full min-w-[860px] text-left text-sm">
                            <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Devis</th>
                                    <th className="px-4 py-3 font-medium">Date</th>
                                    <th className="px-4 py-3 font-medium">Client</th>
                                    <th className="px-4 py-3 font-medium">Validité</th>
                                    <th className="px-4 py-3 font-medium">Statut</th>
                                    <th className="px-4 py-3 text-right font-medium">Total TTC</th>
                                    <th className="px-4 py-3 font-medium">Créé par</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {quotations.data.map((q) => (
                                    <tr key={q.id} onClick={() => router.visit(`/quotations/${q.id}`)} className="cursor-pointer transition-soft hover:bg-sage/50">
                                        <td className="px-4 py-3 font-medium text-ink">{q.quotation_number ?? 'Brouillon'}</td>
                                        <td className="px-4 py-3 text-ink-muted">{formatDate(q.quotation_date)}</td>
                                        <td className="px-4 py-3 text-ink">{q.customer_company ?? q.customer_name ?? '—'}</td>
                                        <td className="px-4 py-3 text-ink-muted">
                                            {q.valid_until ? formatDate(q.valid_until) : '—'}
                                            {expired(q) && <span className="ml-1 text-xs text-warning">(échu)</span>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <DocBadge tone={quotationStatusTone(q.status)}>{label(quotationStatusLabel, q.status)}</DocBadge>
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium tabular-nums text-ink">{formatMoney(q.total_incl_tax, q.currency_code)}</td>
                                        <td className="px-4 py-3 text-ink-muted">{q.created_by?.name ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {quotations.data.map((q) => (
                            <li key={q.id}>
                                <Link href={`/quotations/${q.id}`} className="block rounded-card border border-line bg-surface p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-ink">{q.quotation_number ?? 'Brouillon'}</p>
                                            <p className="text-[13px] text-ink-muted">{formatDate(q.quotation_date)}</p>
                                        </div>
                                        <DocBadge tone={quotationStatusTone(q.status)}>{label(quotationStatusLabel, q.status)}</DocBadge>
                                    </div>
                                    <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">Client</dt>
                                            <dd className="truncate text-ink">{q.customer_company ?? q.customer_name ?? '—'}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">Validité</dt>
                                            <dd className="truncate text-ink-muted">
                                                {q.valid_until ? formatDate(q.valid_until) : '—'}
                                                {expired(q) && <span className="ml-1 text-warning">(échu)</span>}
                                            </dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">Créé par</dt>
                                            <dd className="truncate text-ink-muted">{q.created_by?.name ?? '—'}</dd>
                                        </div>
                                    </dl>
                                    <p className="mt-2 text-base font-semibold tabular-nums text-ink">{formatMoney(q.total_incl_tax, q.currency_code)}</p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={quotations.links} />
        </SalesLayout>
    );
}
