import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SalesLayout from '@/layouts/SalesLayout';
import { useI18n } from '@/i18n';
import { formatDate } from '@/utils/format';
import { deliveryNoteStatusTone } from '@/utils/labels';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Note = { id: number; delivery_note_number: string | null; delivery_date: string; recipient_name: string | null; recipient_company: string | null; status: string; store: { name: string }; sales_order: { id: number; order_number: string } };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = { deliveryNotes: { data: Note[]; links: PageLink[] }; filters: Record<string, string | undefined> };

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

export default function DeliveryNoteIndex({ deliveryNotes, filters }: Props) {
    const { t } = useI18n();
    const statusLabel: Record<string, string> = { draft: t('deliveryNotes.draft'), issued: t('deliveryNotes.issued'), cancelled: t('deliveryNotes.cancelled') };
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get('/delivery-notes', Object.fromEntries(new FormData(event.currentTarget).entries()), { preserveState: true, replace: true });
    };

    return (
        <SalesLayout>
            <Head title={t('deliveryNotes.title')} />
            <PageHeader title={t('deliveryNotes.title')} description={t('deliveryNotes.description')} />

            <form onSubmit={submit} className="mb-6 grid gap-3 rounded-card border border-line bg-raised p-4 md:grid-cols-5">
                <input name="search" defaultValue={filters.search ?? ''} placeholder={t('deliveryNotes.search')} className={`${fieldClass} md:col-span-2`} />
                <input name="delivery_date" type="date" defaultValue={filters.delivery_date ?? ''} className={fieldClass} />
                <select name="status" defaultValue={filters.status ?? ''} className={fieldClass}>
                    <option value="">{t('common.allStatuses')}</option><option value="draft">{t('deliveryNotes.draft')}</option><option value="issued">{t('deliveryNotes.issued')}</option><option value="cancelled">{t('deliveryNotes.cancelled')}</option>
                </select>
                <button type="submit" className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised">
                    {t('deliveryNotes.filter')}
                </button>
            </form>

            {deliveryNotes.data.length === 0 ? (
                <EmptyState title={t('deliveryNotes.empty')} description={t('deliveryNotes.emptyHelp')} />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="w-full text-start text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-2.5">{t('deliveryNotes.number')}</th><th className="px-4 py-2.5">{t('deliveryNotes.deliveryDate')}</th><th className="px-4 py-2.5">{t('deliveryNotes.order')}</th><th className="px-4 py-2.5">{t('deliveryNotes.recipient')}</th><th className="px-4 py-2.5">{t('deliveryNotes.store')}</th><th className="px-4 py-2.5">{t('common.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {deliveryNotes.data.map((note) => (
                                    <tr key={note.id} className="border-t border-line">
                                        <td className="px-4 py-2.5 font-medium text-ink">
                                            <Link href={`/delivery-notes/${note.id}`}><bdi>{note.delivery_note_number ?? t('deliveryNotes.draftNumber').replace('{id}', String(note.id))}</bdi></Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{formatDate(note.delivery_date)}</td>
                                        <td className="px-4 py-2.5"><Link href={`/sales/orders/${note.sales_order.id}`}><bdi>{note.sales_order.order_number}</bdi></Link></td><td className="px-4 py-2.5 text-ink-muted">{note.recipient_company ?? note.recipient_name ?? t('common.counterCustomer')}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{note.store.name}</td>
                                        <td className="px-4 py-2.5"><DocBadge tone={deliveryNoteStatusTone(note.status)}>{statusLabel[note.status] ?? note.status}</DocBadge></td>
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
                                            <bdi className="block truncate text-sm font-semibold text-ink">{note.delivery_note_number ?? t('deliveryNotes.draftNumber').replace('{id}', String(note.id))}</bdi>
                                            <p className="text-[13px] text-ink-muted">{formatDate(note.delivery_date)}</p>
                                        </div>
                                        <DocBadge tone={deliveryNoteStatusTone(note.status)}>{statusLabel[note.status] ?? note.status}</DocBadge>
                                    </div>
                                    <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('deliveryNotes.recipient')}</dt><dd className="truncate text-ink-muted">{note.recipient_company ?? note.recipient_name ?? t('common.counterCustomer')}</dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('deliveryNotes.order')}</dt><dd className="truncate text-ink-muted"><bdi>{note.sales_order.order_number}</bdi></dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('deliveryNotes.store')}</dt>
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
