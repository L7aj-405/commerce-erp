import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import EmptyState from '@/components/ui/EmptyState';
import { AdvancedFilterBar, ChoiceFilter, DateRangeFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Dropdown, MenuButton } from '@/components/ui/Menu';
import SandboxProcurementBadge from '@/components/SandboxProcurementBadge';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import StatusBadge, { type StatusVariant } from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { cancelSupplierRequest, RecordResultDialog, type SourcingRequest, type SourcingTransport } from '@/pages/Quotations/Sourcing';
import { formatDate, formatDateTime, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';

type RequestStatus = 'pending' | 'available' | 'partial' | 'unavailable' | 'confirmed_manually' | 'cancelled';

type Row = {
    id: number;
    status: RequestStatus;
    /** Where the evidence was gathered: a Devis line or a POS cart line. */
    source: 'quotation' | 'pos';
    quotation: { id: number; number: string | null; is_draft: boolean } | null;
    cart: { id: number; number: string; is_open: boolean; stage: 'pos_cart' | 'order_draft' | 'closed' } | null;
    customer: { id: number | null; name: string | null };
    article: { line_type: 'catalog' | 'non_stock' | null; name: string | null; variant: string | null; reference: string | null };
    supplier_name: string | null;
    requested_quantity: string;
    confirmed_quantity: string | null;
    note: string | null;
    requested_by: string | null;
    requested_at: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status: string; source?: string; supplier_id?: number | string; from?: string; to?: string };
type Props = {
    requests: { data: Row[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: Filters;
    suppliers: { id: number; name: string }[];
    can: { record: boolean; cancel: boolean; cancelCart: boolean; viewCustomers: boolean; viewSalesOrders: boolean };
};

const STATUSES: RequestStatus[] = ['pending', 'partial', 'available', 'unavailable', 'confirmed_manually', 'cancelled'];
const VARIANT: Record<RequestStatus, StatusVariant> = {
    pending: 'info',
    partial: 'warning',
    available: 'success',
    unavailable: 'danger',
    confirmed_manually: 'neutral',
    cancelled: 'neutral',
};
const RELOAD = ['requests', 'suppliers', 'errors', 'flash'];

/**
 * POS cart rows answer through the inbox's own routes, which run the SAME cart
 * actions as the POS "Articles à sourcer" modal.
 */
const cartTransport: SourcingTransport = {
    recommendationsUrl: () => '',
    optionsUrl: () => '',
    createRequest: () => undefined,
    recordResult: (requestId, payload, callbacks) =>
        router.patch(`/procurement/supplier-requests/${requestId}`, payload, {
            preserveScroll: true,
            preserveState: true,
            only: RELOAD,
            onError: (e) => callbacks.onError?.(e),
            onSuccess: () => callbacks.onSuccess?.(),
            onFinish: () => callbacks.onFinish?.(),
        }),
    cancelRequest: (requestId, callbacks) =>
        router.post(`/procurement/supplier-requests/${requestId}/cancel`, {}, {
            preserveScroll: true,
            preserveState: true,
            only: RELOAD,
            onFinish: () => callbacks.onFinish?.(),
        }),
};

function statusKey(status: RequestStatus): TranslationKey {
    return `supplierRequests.status.${status}` as TranslationKey;
}

/** The canonical request shape the shared sourcing response dialog expects. */
function toSourcingRequest(row: Row): SourcingRequest {
    return {
        id: row.id,
        supplier_id: null,
        supplier_name: row.supplier_name,
        status: row.status as SourcingRequest['status'],
        requested_quantity: row.requested_quantity,
        confirmed_quantity: row.confirmed_quantity,
        note: row.note,
        requested_at: row.requested_at,
        requested_by: row.requested_by,
        resolved_at: row.resolved_at,
        resolved_by: row.resolved_by,
    };
}

const svg = { width: 15, height: 15, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true };
function IconReply() { return <svg {...svg}><path d="M4 5h16v11H8l-4 4V5Z" /><path d="m9 10.5 2 2 4-4" /></svg>; }
function IconEye() { return <svg {...svg}><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></svg>; }
function IconX() { return <svg {...svg}><path d="M18 6 6 18M6 6l12 12" /></svg>; }
function IconMore() { return <svg {...svg}><circle cx="5" cy="12" r="1.3" /><circle cx="12" cy="12" r="1.3" /><circle cx="19" cy="12" r="1.3" /></svg>; }

/** Extra badge styling: one line always; manual / cancelled get a non-color cue too. */
const BADGE_EXTRA: Partial<Record<RequestStatus, string>> = {
    confirmed_manually: 'ring-1 ring-inset ring-line-strong',
    cancelled: 'text-ink-faint line-through decoration-ink-faint/60',
};

function RowStatusBadge({ status, label }: { status: RequestStatus; label: string }) {
    return (
        <StatusBadge variant={VARIANT[status]} className={`whitespace-nowrap !px-2.5 !py-0.5 ${BADGE_EXTRA[status] ?? ''}`}>
            {label}
        </StatusBadge>
    );
}

type MenuItem = { label: string; icon: ReactNode; onSelect: () => void; danger?: boolean; disabled?: boolean };

/** Compact "•••" menu for secondary row actions (shared Dropdown: portalled, RTL-aware). */
function OverflowMenu({ label, items }: { label: string; items: MenuItem[] }) {
    if (items.length === 0) return null;
    return (
        <Dropdown
            label={label}
            icon={<IconMore />}
            hideLabel
            align="end"
            width="w-48"
            buttonClassName="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-field border border-line-strong bg-surface text-ink-muted transition-soft hover:bg-raised hover:text-ink"
        >
            {items.map((item) => (
                <MenuButton key={item.label} onClick={item.onSelect} icon={item.icon} tone={item.danger ? 'danger' : 'default'} disabled={item.disabled}>
                    {item.label}
                </MenuButton>
            ))}
        </Dropdown>
    );
}

export default function SupplierRequestsIndex({ requests, filters, suppliers, can }: Props) {
    const { t } = useI18n();
    const [recording, setRecording] = useState<Row | null>(null);
    const [detail, setDetail] = useState<Row | null>(null);
    const [cancelling, setCancelling] = useState<number | null>(null);
    // Exactly the params QuotationSupplierRequestController@index validates; `status`
    // defaults to `open` on the server, so clearing it returns to the open view.
    const f = useQueryFilters('/procurement/supplier-requests', filters as Record<string, string | undefined>, {
        keys: ['search', 'status', 'source', 'supplier_id', 'from', 'to'],
    });
    const statusOptions = [
        { value: 'open', label: t('supplierRequests.openStatuses') },
        { value: 'all', label: t('common.allStatuses') },
        ...STATUSES.map((status) => ({ value: status, label: t(statusKey(status)) })),
    ];
    const sourceOptions = [
        { value: 'quotation', label: t('supplierRequests.sourceQuotation') },
        { value: 'pos', label: t('supplierRequests.sourceCart') },
    ];
    const supplierId = filters.supplier_id ? String(filters.supplier_id) : undefined;
    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status !== 'open') {
        chips.push({
            key: 'status',
            label: t('common.status'),
            value: statusOptions.find((option) => option.value === filters.status)?.label ?? filters.status,
            onRemove: () => f.remove('status'),
        });
    }
    if (filters.source) {
        chips.push({
            key: 'source',
            label: t('supplierRequests.source'),
            value: sourceOptions.find((option) => option.value === filters.source)?.label ?? filters.source,
            onRemove: () => f.remove('source'),
        });
    }
    if (supplierId) {
        chips.push({
            key: 'supplier',
            label: t('sourcing.supplier'),
            value: suppliers.find((supplier) => String(supplier.id) === supplierId)?.name ?? supplierId,
            onRemove: () => f.remove('supplier_id'),
        });
    }
    if (filters.from || filters.to) {
        chips.push({
            key: 'period',
            label: t('supplierRequests.date'),
            value: `${filters.from ? formatDate(filters.from) : '…'} – ${filters.to ? formatDate(filters.to) : '…'}`,
            onRemove: () => f.patch({ from: undefined, to: undefined }),
        });
    }
    const filtered = chips.length > 0;

    // UI affordances only — the canonical actions re-check permission and draft state.
    const editable = (row: Row) => (row.quotation ? row.quotation.is_draft : row.cart?.is_open === true);
    const canRecord = (row: Row) => can.record && editable(row) && (row.status === 'pending' || row.status === 'partial');
    const canCancel = (row: Row) => (row.cart ? can.cancelCart : can.cancel) && editable(row) && row.status === 'pending';
    const cancel = (row: Row) => {
        setCancelling(row.id);
        if (row.quotation) {
            cancelSupplierRequest(row.quotation.id, row.id, { only: RELOAD, onFinish: () => setCancelling(null) });
        } else {
            cartTransport.cancelRequest(row.id, { onFinish: () => setCancelling(null) });
        }
    };

    const sourceLabel = (row: Row) =>
        row.quotation ? (row.quotation.number ?? `${t('supplierRequests.draft')} #${row.quotation.id}`) : (row.cart?.number ?? '—');
    const sourceHref = (row: Row): string | null =>
        row.quotation ? `/quotations/${row.quotation.id}` : row.cart && can.viewSalesOrders ? `/sales/orders/${row.cart.id}` : null;

    /** Source badge (Devis / Panier · POS) + link to the request's own Devis or cart order. */
    const Source = ({ row, compact = false }: { row: Row; compact?: boolean }) => {
        const href = sourceHref(row);
        return (
            <span className="inline-flex flex-col items-start gap-0.5">
                <span className={`rounded px-1.5 py-px text-[11px] font-semibold ${row.source === 'pos' ? 'bg-info-soft text-info' : 'bg-raised text-ink-muted'}`}>
                    {row.source === 'pos' ? t('supplierRequests.sourceCart') : t('supplierRequests.sourceQuotation')}
                </span>
                {href ? (
                    <Link href={href} className={`font-medium text-ink underline-offset-2 hover:underline ${compact ? 'underline' : ''}`}>
                        {sourceLabel(row)}
                    </Link>
                ) : (
                    <span className="font-medium text-ink">{sourceLabel(row)}</span>
                )}
                {row.quotation && !row.quotation.is_draft && <span className="block text-[11px] text-ink-faint">{t('supplierRequests.frozen')}</span>}
                {row.cart && !row.cart.is_open && (
                    <span className="block text-[11px] text-ink-faint">{t(`supplierRequests.cartStage.${row.cart.stage}` as TranslationKey)}</span>
                )}
            </span>
        );
    };

    const Article = ({ row }: { row: Row }) => (
        <div className="min-w-0">
            <p className="line-clamp-2 font-medium text-ink" title={row.article.name ?? undefined}>
                {row.article.name ?? '—'}
            </p>
            <p className="truncate text-xs text-ink-faint">
                {row.article.line_type === 'non_stock' && (
                    <span className="me-1 rounded bg-raised px-1 text-[11px] font-medium">{t('quotations.nonStock')}</span>
                )}
                {[row.article.variant, row.article.reference].filter(Boolean).join(' · ')}
            </p>
        </div>
    );

    const Customer = ({ row }: { row: Row }) =>
        row.customer.name ? (
            can.viewCustomers && row.customer.id ? (
                <Link href={`/customers/${row.customer.id}`} className="text-ink underline-offset-2 hover:underline">
                    {row.customer.name}
                </Link>
            ) : (
                <span>{row.customer.name}</span>
            )
        ) : (
            <span className="text-ink-faint">—</span>
        );

    const Confirmed = ({ row }: { row: Row }) =>
        row.status === 'confirmed_manually' ? (
            <span className="inline-flex flex-col items-end">
                <span className="font-semibold tabular-nums text-ink">{formatQuantity(row.confirmed_quantity)}</span>
                <span className="text-[11px] text-ink-faint">{t('supplierRequests.manualShort')}</span>
            </span>
        ) : row.confirmed_quantity !== null ? (
            <span className="tabular-nums">{formatQuantity(row.confirmed_quantity)}</span>
        ) : (
            <span className="text-ink-faint">—</span>
        );

    const Actions = ({ row, block = false }: { row: Row; block?: boolean }) => {
        const secondary: MenuItem[] = [
            { label: t('supplierRequests.details'), icon: <IconEye />, onSelect: () => setDetail(row) },
            ...(canCancel(row)
                ? [{ label: t('sourcing.cancelRequest'), icon: <IconX />, onSelect: () => cancel(row), danger: true, disabled: cancelling === row.id }]
                : []),
        ];

        return (
            <div className={`flex items-center gap-1.5 ${block ? 'w-full' : 'justify-end'}`}>
                {canRecord(row) && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setRecording(row)}
                        className={`whitespace-nowrap ${block ? 'flex-1' : ''}`}
                    >
                        <IconReply />
                        {t('sourcing.recordResult')}
                    </Button>
                )}
                {/* Wide desktop: secondary actions as subtle icon buttons with tooltips. */}
                <span className="hidden items-center gap-1 2xl:inline-flex">
                    <Button size="sm" variant="ghost" onClick={() => setDetail(row)} title={t('supplierRequests.details')} className="whitespace-nowrap !px-2.5">
                        <IconEye />
                        {t('supplierRequests.details')}
                    </Button>
                    {canCancel(row) && (
                        <button
                            type="button"
                            disabled={cancelling === row.id}
                            onClick={() => cancel(row)}
                            aria-label={t('sourcing.cancelRequest')}
                            title={t('sourcing.cancelRequest')}
                            className="inline-flex size-8 items-center justify-center rounded-field text-ink-faint transition-soft hover:bg-danger-soft hover:text-danger disabled:opacity-40"
                        >
                            <IconX />
                        </button>
                    )}
                </span>
                {/* Tighter widths: secondary actions collapse into the overflow menu. */}
                <span className={`2xl:hidden ${block && !canRecord(row) ? 'ms-auto' : ''}`}>
                    <OverflowMenu label={t('supplierRequests.moreActions')} items={secondary} />
                </span>
            </div>
        );
    };

    return (
        <ProcurementLayout>
            <Head title={t('supplierRequests.title')} />
            <PageHeader title={t('supplierRequests.title')} description={t('supplierRequests.description')} />
            <SandboxProcurementBadge />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('supplierRequests.title')}
                    search={
                        <FilterSearch
                            value={f.search}
                            onChange={f.setSearch}
                            loading={f.searching}
                            label={t('common.search')}
                            placeholder={t('supplierRequests.searchPlaceholder')}
                        />
                    }
                    chips={chips}
                    activeCount={chips.length}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    {/* Every status is explicit; clearing returns to the server default (open). */}
                    <ChoiceFilter label={t('common.status')} options={statusOptions} value={filters.status} onChange={(value) => f.set('status', value)} />
                    <ChoiceFilter
                        label={t('supplierRequests.source')}
                        allLabel={t('supplierRequests.allSources')}
                        options={sourceOptions}
                        value={filters.source}
                        onChange={(value) => f.set('source', value)}
                    />
                    <ChoiceFilter
                        label={t('sourcing.supplier')}
                        allLabel={t('supplierRequests.allSuppliers')}
                        options={suppliers.map((supplier) => ({ value: String(supplier.id), label: supplier.name }))}
                        value={supplierId}
                        onChange={(value) => f.set('supplier_id', value)}
                    />
                    <DateRangeFilter
                        label={t('supplierRequests.date')}
                        from={filters.from}
                        to={filters.to}
                        onChange={(range) => f.patch({ from: range.from, to: range.to })}
                    />
                </AdvancedFilterBar>
            </div>

            {requests.data.length === 0 ? (
                <EmptyState
                    title={filtered ? t('filters.noMatch') : t('supplierRequests.empty')}
                    description={filtered ? t('filters.noMatchHelp') : t('supplierRequests.emptyDescription')}
                    actions={
                        filtered ? (
                            <Button variant="secondary" onClick={f.reset}>
                                {t('filters.reset')}
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <div className={`transition-opacity ${f.loading ? 'opacity-55' : ''}`} aria-busy={f.loading || undefined}>
                    {/* Desktop (≥ 1280px): table. "Créée par" only on wide screens (it is in Détails). */}
                    <div className="hidden overflow-x-auto surface-card xl:block">
                        <table className="erp-table w-full min-w-[60rem] text-start text-sm">
                            <thead>
                                <tr className="[&>th]:px-2.5 [&>th]:py-3 [&>th]:font-medium [&>th]:whitespace-nowrap">
                                    <th>{t('common.status')}</th>
                                    <th className="w-[15rem]">{t('supplierRequests.article')}</th>
                                    <th>{t('supplierRequests.source')}</th>
                                    <th>{t('supplierRequests.customer')}</th>
                                    <th>{t('sourcing.supplier')}</th>
                                    <th className="text-end">{t('supplierRequests.requestedQty')}</th>
                                    <th className="text-end">{t('supplierRequests.confirmedQty')}</th>
                                    <th className="hidden 2xl:table-cell">{t('supplierRequests.createdBy')}</th>
                                    <th>{t('supplierRequests.date')}</th>
                                    <th className="min-w-[12.5rem] text-end 2xl:min-w-[17rem]">{t('supplierRequests.actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line [&>tr>td]:px-2.5 [&>tr>td]:py-3 [&>tr>td]:align-top">
                                {requests.data.map((row) => (
                                    <tr key={row.id}>
                                        <td>
                                            <RowStatusBadge status={row.status} label={t(statusKey(row.status))} />
                                        </td>
                                        <td className="max-w-[15rem]">
                                            <Article row={row} />
                                        </td>
                                        <td className="whitespace-nowrap">
                                            <Source row={row} />
                                        </td>
                                        <td className="max-w-[11rem]">
                                            <span className="line-clamp-2 break-words">
                                                <Customer row={row} />
                                            </span>
                                        </td>
                                        <td className="max-w-[11rem] break-words text-ink-muted">
                                            {row.status === 'confirmed_manually' ? (
                                                <span>
                                                    <span className="block text-xs text-ink">{t('sourcing.manualConfirmation')}</span>
                                                    {row.supplier_name ?? '—'}
                                                </span>
                                            ) : (
                                                (row.supplier_name ?? '—')
                                            )}
                                        </td>
                                        <td className="text-end tabular-nums whitespace-nowrap">
                                            {row.status === 'confirmed_manually' ? <span className="text-ink-faint">—</span> : formatQuantity(row.requested_quantity)}
                                        </td>
                                        <td className="text-end whitespace-nowrap">
                                            <Confirmed row={row} />
                                        </td>
                                        <td className="hidden text-ink-muted 2xl:table-cell">{row.requested_by ?? '—'}</td>
                                        <td className="whitespace-nowrap text-ink-muted">{row.requested_at ? formatDate(row.requested_at) : '—'}</td>
                                        <td>
                                            <Actions row={row} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Tablet and mobile (< 1280px): compact cards, never a squeezed table. */}
                    <ul className="grid gap-3 md:grid-cols-2 xl:hidden">
                        {requests.data.map((row) => (
                            <li key={row.id} className="flex min-w-0 flex-col surface-card p-3 text-sm">
                                <div className="flex items-center justify-between gap-2">
                                    <RowStatusBadge status={row.status} label={t(statusKey(row.status))} />
                                    <span className="whitespace-nowrap text-xs text-ink-faint">{row.requested_at ? formatDate(row.requested_at) : '—'}</span>
                                </div>
                                <div className="mt-2">
                                    <Article row={row} />
                                </div>
                                <dl className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2 text-xs text-ink-muted">
                                    <div className="min-w-0">
                                        <dt>{t('supplierRequests.source')}</dt>
                                        <dd>
                                            <Source row={row} compact />
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt>{t('supplierRequests.customer')}</dt>
                                        <dd className="truncate">
                                            <Customer row={row} />
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt>{t('sourcing.supplier')}</dt>
                                        <dd className="break-words text-ink">
                                            {row.status === 'confirmed_manually' ? t('sourcing.manualConfirmation') : (row.supplier_name ?? '—')}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt>
                                            {t('supplierRequests.requestedQty')} / {t('supplierRequests.confirmedQty')}
                                        </dt>
                                        <dd className="tabular-nums text-ink">
                                            {row.status === 'confirmed_manually' ? '—' : formatQuantity(row.requested_quantity)} /{' '}
                                            {row.confirmed_quantity !== null ? formatQuantity(row.confirmed_quantity) : '—'}
                                        </dd>
                                    </div>
                                </dl>
                                {/* mt-auto keeps actions aligned at the bottom of side-by-side cards. */}
                                <div className="mt-auto pt-3">
                                    <div className="border-t border-line pt-2.5">
                                        <Actions row={row} block />
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>

                    <div className="mt-4">
                        <TablePager links={requests.links} from={requests.from} to={requests.to} total={requests.total} />
                    </div>
                </div>
            )}

            {recording && (
                <RecordResultDialog
                    quotationId={recording.quotation?.id}
                    request={toSourcingRequest(recording)}
                    onClose={() => setRecording(null)}
                    only={RELOAD}
                    transport={recording.quotation ? undefined : cartTransport}
                />
            )}

            <Dialog
                open={detail !== null}
                onClose={() => setDetail(null)}
                title={t('supplierRequests.detailTitle')}
                description={detail ? `${detail.source === 'pos' ? t('supplierRequests.sourceCart') : t('supplierRequests.sourceQuotation')} · ${sourceLabel(detail)}` : undefined}
                footer={
                    <div className="flex justify-end gap-2">
                        {detail && sourceHref(detail) && (
                            <Link
                                href={sourceHref(detail) as string}
                                className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                            >
                                {detail.quotation ? t('supplierRequests.openQuotation') : t('supplierRequests.openOrder')}
                            </Link>
                        )}
                        <Button variant="secondary" onClick={() => setDetail(null)}>
                            {t('common.close')}
                        </Button>
                    </div>
                }
            >
                {detail && (
                    <dl className="grid gap-x-6 gap-y-3 px-4 py-4 text-sm sm:grid-cols-2 sm:px-6">
                        {(
                            [
                                ['supplierRequests.article', <Article key="a" row={detail} />],
                                ['common.status', <StatusBadge key="s" variant={VARIANT[detail.status]}>{t(statusKey(detail.status))}</StatusBadge>],
                                ['supplierRequests.customer', <Customer key="c" row={detail} />],
                                [
                                    'sourcing.supplier',
                                    detail.status === 'confirmed_manually'
                                        ? `${t('sourcing.manualConfirmation')}${detail.supplier_name ? ` · ${detail.supplier_name}` : ''}`
                                        : (detail.supplier_name ?? '—'),
                                ],
                                ['supplierRequests.requestedQty', detail.status === 'confirmed_manually' ? '—' : formatQuantity(detail.requested_quantity)],
                                ['supplierRequests.confirmedQty', detail.confirmed_quantity !== null ? formatQuantity(detail.confirmed_quantity) : '—'],
                                ['supplierRequests.createdBy', detail.requested_by ?? '—'],
                                ['supplierRequests.requestedAt', detail.requested_at ? formatDateTime(detail.requested_at) : '—'],
                                ['supplierRequests.resolvedBy', detail.resolved_by ?? '—'],
                                ['supplierRequests.resolvedAt', detail.resolved_at ? formatDateTime(detail.resolved_at) : '—'],
                            ] as [TranslationKey, ReactNode][]
                        ).map(([label, value]) => (
                            <div key={label} className="min-w-0">
                                <dt className="text-xs text-ink-muted">{t(label)}</dt>
                                <dd className="mt-0.5 text-ink">{value}</dd>
                            </div>
                        ))}
                        <div className="sm:col-span-2">
                            <dt className="text-xs text-ink-muted">{t('sourcing.internalNote')}</dt>
                            <dd className="mt-0.5 whitespace-pre-line text-ink">{detail.note || '—'}</dd>
                        </div>
                    </dl>
                )}
            </Dialog>
        </ProcurementLayout>
    );
}
