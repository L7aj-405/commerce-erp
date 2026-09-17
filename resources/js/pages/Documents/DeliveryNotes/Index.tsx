import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate } from '@/utils/format';
import { deliveryNoteStatusLabel, deliveryNoteStatusTone, label } from '@/utils/labels';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Note = { id: number; delivery_note_number: string | null; delivery_date: string; recipient_name: string | null; recipient_company: string | null; status: string; store: { name: string }; sales_order: { id: number; order_number: string } };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = { deliveryNotes: { data: Note[]; links: PageLink[] }; filters: Record<string, string | undefined> };

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

export default function DeliveryNoteIndex({ deliveryNotes, filters }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get('/delivery-notes', Object.fromEntries(new FormData(event.currentTarget).entries()), { preserveState: true, replace: true });
    };

    return (
        <SalesLayout>
            <Head title="Bons de livraison" />
            <PageHeader title="Bons de livraison" description="Documents de livraison des commandes préparées dans la boutique active." />

            <form onSubmit={submit} className="mb-6 grid gap-3 rounded-card border border-line bg-raised p-4 md:grid-cols-5">
                <input name="search" defaultValue={filters.search ?? ''} placeholder="N° BL, commande, destinataire" className={`${fieldClass} md:col-span-2`} />
                <input name="delivery_date" type="date" defaultValue={filters.delivery_date ?? ''} className={fieldClass} />
                <select name="status" defaultValue={filters.status ?? ''} className={fieldClass}>
                    <option value="">Tous les statuts</option>
                    <option value="draft">Brouillon</option>
                    <option value="issued">Émis</option>
                    <option value="cancelled">Annulé</option>
                </select>
                <button type="submit" className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised">
                    Filtrer
                </button>
            </form>

            {deliveryNotes.data.length === 0 ? (
                <EmptyState title="Aucun bon de livraison." description="Aucun document ne correspond à ces filtres." />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-2.5">N° BL</th>
                                    <th className="px-4 py-2.5">Date de livraison</th>
                                    <th className="px-4 py-2.5">Commande</th>
                                    <th className="px-4 py-2.5">Destinataire</th>
                                    <th className="px-4 py-2.5">Boutique</th>
                                    <th className="px-4 py-2.5">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {deliveryNotes.data.map((note) => (
                                    <tr key={note.id} className="border-t border-line">
                                        <td className="px-4 py-2.5 font-medium text-ink">
                                            <Link href={`/delivery-notes/${note.id}`}>{note.delivery_note_number ?? `Brouillon #${note.id}`}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{formatDate(note.delivery_date)}</td>
                                        <td className="px-4 py-2.5"><Link href={`/sales/orders/${note.sales_order.id}`}>{note.sales_order.order_number}</Link></td>
                                        <td className="px-4 py-2.5 text-ink-muted">{note.recipient_company ?? note.recipient_name ?? 'Client comptoir'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{note.store.name}</td>
                                        <td className="px-4 py-2.5"><DocBadge tone={deliveryNoteStatusTone(note.status)}>{label(deliveryNoteStatusLabel, note.status)}</DocBadge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {deliveryNotes.data.map((note) => (
                            <li key={note.id}>
                                <Link href={`/delivery-notes/${note.id}`} className="block rounded-card border border-line bg-surface p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-ink">{note.delivery_note_number ?? `Brouillon #${note.id}`}</p>
                                            <p className="text-[13px] text-ink-muted">{formatDate(note.delivery_date)}</p>
                                        </div>
                                        <DocBadge tone={deliveryNoteStatusTone(note.status)}>{label(deliveryNoteStatusLabel, note.status)}</DocBadge>
                                    </div>
                                    <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">Destinataire</dt>
                                            <dd className="truncate text-ink-muted">{note.recipient_company ?? note.recipient_name ?? 'Client comptoir'}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">Commande</dt>
                                            <dd className="truncate text-ink-muted">{note.sales_order.order_number}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">Boutique</dt>
                                            <dd className="truncate text-ink-muted">{note.store.name}</dd>
                                        </div>
                                    </dl>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={deliveryNotes.links} />
        </SalesLayout>
    );
}
