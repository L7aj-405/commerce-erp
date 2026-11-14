import SandboxDocumentBadge from '@/components/SandboxDocumentBadge';
import { AdvancedFilterBar, ChoiceFilter, DateFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { rowLink, TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import type { SharedPageProps } from '@/types/app';
import { formatDate } from '@/utils/format';
import { deliveryNoteStatusTone } from '@/utils/labels';
import { Head, Link, usePage } from '@inertiajs/react';

type Note = {
    id: number;
    sandbox_id?: number | null;
    delivery_note_number: string | null;
    delivery_date: string;
    recipient_name: string | null;
    recipient_company: string | null;
    status: string;
    store: { name: string };
    sales_order: { id: number; order_number: string };
};
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string; delivery_date?: string };
type Props = { deliveryNotes: { data: Note[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number }; filters: Filters };

// Exactly the single-value params DeliveryNoteController@index validates.
const FILTER_KEYS = ['search', 'status', 'delivery_date'] as const;

export default function DeliveryNoteIndex({ deliveryNotes, filters }: Props) {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const canViewOrders = tenant.permissions.includes('sales_orders.view');
    const f = useQueryFilters('/delivery-notes', filters, { keys: [...FILTER_KEYS] });

    const statusOptions = [
        { value: 'draft', label: t('deliveryNotes.draft') },
        { value: 'issued', label: t('deliveryNotes.issued') },
        { value: 'cancelled', label: t('deliveryNotes.cancelled') },
    ];
    const statusLabel = (value: string) => statusOptions.find((option) => option.value === value)?.label ?? value;

    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: statusLabel(filters.status), onRemove: () => f.remove('status') });
    if (filters.delivery_date)
        chips.push({ key: 'delivery_date', label: t('deliveryNotes.deliveryDate'), value: formatDate(filters.delivery_date), onRemove: () => f.remove('delivery_date') });

    const number = (note: Note) => note.delivery_note_number ?? t('deliveryNotes.draftNumber').replace('{id}', String(note.id));
    const recipient = (note: Note) => note.recipient_company ?? note.recipient_name ?? t('common.counterCustomer');
    const orderRef = (note: Note) =>
        canViewOrders ? (
            <Link href={`/sales/orders/${note.sales_order.id}`} className="text-ink-muted underline-offset-2 hover:text-accent hover:underline">
                <bdi>{note.sales_order.order_number}</bdi>
            </Link>
        ) : (
            <bdi className="text-ink-muted">{note.sales_order.order_number}</bdi>
        );

    const filtered = f.activeCount > 0;
    const resetAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : undefined;
    const emptyTitle = filtered ? t('filters.noMatch') : t('deliveryNotes.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('deliveryNotes.emptyHelp');

    return (
        <SalesLayout>
            <Head title={t('deliveryNotes.title')} />
            <PageHeader title={t('deliveryNotes.title')} description={t('deliveryNotes.description')} />

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('deliveryNotes.search')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('common.status')} options={statusOptions} value={filters.status} onChange={(value) => f.set('status', value)} />
                    <DateFilter label={t('deliveryNotes.deliveryDate')} value={filters.delivery_date} onChange={(value) => f.set('delivery_date', value)} />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('deliveryNotes.title')}
                state={deliveryNotes.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={resetAction}
                className="hidden xl:block"
                footer={
                    deliveryNotes.data.length === 0 ? undefined : (
                        <TablePager links={deliveryNotes.links} from={deliveryNotes.from} to={deliveryNotes.to} total={deliveryNotes.total} />
                    )
                }
            >
                <table className="erp-table min-w-[820px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('deliveryNotes.number')}</th>
                            <th className="px-4 py-2.5">{t('deliveryNotes.deliveryDate')}</th>
                            <th className="px-4 py-2.5">{t('deliveryNotes.order')}</th>
                            <th className="px-4 py-2.5">{t('deliveryNotes.recipient')}</th>
                            <th className="px-4 py-2.5">{t('deliveryNotes.store')}</th>
                            <th className="px-4 py-2.5">{t('common.status')}</th>
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {deliveryNotes.data.map((note) => {
                            const row = rowLink(`/delivery-notes/${note.id}`);
                            return (
                                <tr key={note.id} onClick={row.onClick} className={row.className}>
                                    <td className="px-4 py-3">
                                        <span className="inline-flex flex-wrap items-center gap-1.5">
                                            <Link href={`/delivery-notes/${note.id}`} className="font-semibold text-ink hover:text-accent hover:underline">
                                                <bdi>{number(note)}</bdi>
                                            </Link>
                                            <SandboxDocumentBadge kind="delivery" sandboxId={note.sandbox_id} />
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{formatDate(note.delivery_date)}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">{orderRef(note)}</td>
                                    <td className="max-w-72 truncate px-4 py-3 font-medium text-ink">{recipient(note)}</td>
                                    <td className="px-4 py-3 text-ink-muted">{note.store.name}</td>
                                    <td className="px-4 py-3">
                                        <DocBadge tone={deliveryNoteStatusTone(note.status)}>{statusLabel(note.status)}</DocBadge>
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        <ButtonLink href={`/delivery-notes/${note.id}`} size="sm" variant="outline">
                                            {t('common.open')}
                                            <span className="sr-only"> {number(note)}</span>
                                        </ButtonLink>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {/* Below xl: cards (the filter bar above is shared) */}
            <div className="xl:hidden" aria-busy={f.loading || undefined}>
                {deliveryNotes.data.length === 0 ? (
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={resetAction} />
                ) : (
                    <ul className={`grid gap-3 transition-opacity sm:grid-cols-2 lg:grid-cols-3 ${f.loading ? 'opacity-55' : ''}`}>
                        {deliveryNotes.data.map((note) => (
                            <li key={note.id} className="surface-card flex flex-col p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/delivery-notes/${note.id}`} className="block truncate text-base font-semibold text-ink hover:underline">
                                            <bdi>{number(note)}</bdi>
                                        </Link>
                                        <p className="text-[13px] text-ink-muted tabular-nums">{formatDate(note.delivery_date)}</p>
                                    </div>
                                    <DocBadge tone={deliveryNoteStatusTone(note.status)}>{statusLabel(note.status)}</DocBadge>
                                </div>
                                <p className="mt-2 truncate text-sm font-medium text-ink">{recipient(note)}</p>
                                <p className="mt-1 text-[13px] text-ink-muted">
                                    {t('deliveryNotes.order')} : {orderRef(note)} · {note.store.name}
                                </p>
                                <SandboxDocumentBadge kind="delivery" sandboxId={note.sandbox_id} />
                                <ButtonLink href={`/delivery-notes/${note.id}`} size="sm" variant="outline" className="mt-3 w-full">
                                    {t('common.open')}
                                    <span className="sr-only"> {number(note)}</span>
                                </ButtonLink>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-4">
                    <TablePager links={deliveryNotes.links} from={deliveryNotes.from} to={deliveryNotes.to} total={deliveryNotes.total} />
                </div>
            </div>
        </SalesLayout>
    );
}
