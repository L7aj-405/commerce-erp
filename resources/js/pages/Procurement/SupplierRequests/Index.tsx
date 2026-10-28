import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import EmptyState from '@/components/ui/EmptyState';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import StatusBadge, { type StatusVariant } from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { cancelSupplierRequest, RecordResultDialog, type SourcingRequest } from '@/pages/Quotations/Sourcing';
import { formatDate, formatDateTime, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

type RequestStatus = 'pending' | 'available' | 'partial' | 'unavailable' | 'confirmed_manually' | 'cancelled';

type Row = {
    id: number;
    status: RequestStatus;
    quotation: { id: number; number: string | null; is_draft: boolean };
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
type Filters = { search?: string; status: string; supplier_id?: number | string; from?: string; to?: string };
type Props = {
    requests: { data: Row[]; links: LinkData[] };
    filters: Filters;
    suppliers: { id: number; name: string }[];
    can: { record: boolean; cancel: boolean; viewCustomers: boolean };
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

/**
 * Compact "•••" menu for secondary row actions. Positioned `fixed` from the
 * trigger so the table's horizontal scroll container can never clip it.
 */
function OverflowMenu({ label, items }: { label: string; items: MenuItem[] }) {
    const [position, setPosition] = useState<{ top: number; right: number } | null>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const open = position !== null;

    useEffect(() => {
        if (!open) return;
        const close = () => setPosition(null);
        const onPointer = (event: MouseEvent) => {
            const target = event.target as Node;
            if (!menuRef.current?.contains(target) && !triggerRef.current?.contains(target)) close();
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                close();
                triggerRef.current?.focus();
            }
        };
        document.addEventListener('mousedown', onPointer);
        document.addEventListener('keydown', onKey);
        window.addEventListener('resize', close);
        window.addEventListener('scroll', close, true);
        menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]:not([disabled])')?.focus();
        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('resize', close);
            window.removeEventListener('scroll', close, true);
        };
    }, [open]);

    const toggle = () => {
        if (open) return setPosition(null);
        const rect = triggerRef.current?.getBoundingClientRect();
        if (rect) setPosition({ top: rect.bottom + 6, right: Math.max(8, window.innerWidth - rect.right) });
    };

    if (items.length === 0) return null;

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={label}
                title={label}
                onClick={toggle}
                className="inline-flex size-8 shrink-0 items-center justify-center rounded-field border border-line-strong bg-surface text-ink-muted transition-soft hover:bg-raised hover:text-ink"
            >
                <IconMore />
            </button>
            {position && (
                <div
                    ref={menuRef}
                    role="menu"
                    style={{ top: position.top, right: position.right }}
                    className="fixed z-40 w-44 rounded-field border border-line bg-surface p-1 shadow-pop"
                >
                    {items.map((item) => (
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            disabled={item.disabled}
                            onClick={() => {
                                setPosition(null);
                                item.onSelect();
                            }}
                            className={`flex min-h-9 w-full items-center gap-2 rounded-field px-2.5 text-start text-[13px] transition-soft hover:bg-sage disabled:opacity-50 ${item.danger ? 'text-danger' : 'text-ink'}`}
                        >
                            {item.icon}
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        </>
    );
}

export default function SupplierRequestsIndex({ requests, filters, suppliers, can }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [recording, setRecording] = useState<Row | null>(null);
    const [detail, setDetail] = useState<Row | null>(null);
    const [cancelling, setCancelling] = useState<number | null>(null);

    const current = {
        search: filters.search || undefined,
        status: filters.status,
        supplier_id: filters.supplier_id ? String(filters.supplier_id) : undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
    };
    const visit = (patch: Record<string, string | undefined>) =>
        router.get('/procurement/supplier-requests', { ...current, ...patch, page: undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search: search || undefined }), 280);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // UI affordances only — the canonical actions re-check permission and draft state.
    const canRecord = (row: Row) => can.record && row.quotation.is_draft && (row.status === 'pending' || row.status === 'partial');
    const canCancel = (row: Row) => can.cancel && row.quotation.is_draft && row.status === 'pending';
    const cancel = (row: Row) => {
        setCancelling(row.id);
        cancelSupplierRequest(row.quotation.id, row.id, { only: RELOAD, onFinish: () => setCancelling(null) });
    };

    const quotationLabel = (row: Row) => row.quotation.number ?? `${t('supplierRequests.draft')} #${row.quotation.id}`;

    const Article = ({ row }: { row: Row }) => (
        <div className="min-w-0">
            <p className="line-clamp-2 font-medium text-ink" title={row.article.name ?? undefined}>
                {row.article.name ?? '—'}
            </p>
            <p className="truncate text-xs text-ink-faint">
                {row.article.line_type === 'non_stock' && (
                    <span className="me-1 rounded bg-raised px-1 text-[10px] uppercase tracking-wide">{t('quotations.nonStock')}</span>
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
                <span className="text-[10px] uppercase tracking-wide text-ink-faint">{t('supplierRequests.manualShort')}</span>
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

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder={t('supplierRequests.searchPlaceholder')} />
                <FilterSelect aria-label={t('common.status')} value={filters.status} onChange={(e) => visit({ status: e.target.value })}>
                    <option value="open">{t('supplierRequests.openStatuses')}</option>
                    <option value="all">{t('common.allStatuses')}</option>
                    {STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {t(statusKey(s))}
                        </option>
                    ))}
                </FilterSelect>
                <FilterSelect
                    aria-label={t('sourcing.supplier')}
                    value={current.supplier_id ?? ''}
                    onChange={(e) => visit({ supplier_id: e.target.value || undefined })}
                >
                    <option value="">{t('supplierRequests.allSuppliers')}</option>
                    {suppliers.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </FilterSelect>
                <label className="flex items-center gap-1.5 text-xs text-ink-muted">
                    {t('supplierRequests.from')}
                    <input type="date" value={filters.from ?? ''} onChange={(e) => visit({ from: e.target.value || undefined })} className="control-input h-10" />
                </label>
                <label className="flex items-center gap-1.5 text-xs text-ink-muted">
                    {t('supplierRequests.to')}
                    <input type="date" value={filters.to ?? ''} onChange={(e) => visit({ to: e.target.value || undefined })} className="control-input h-10" />
                </label>
            </div>

            {requests.data.length === 0 ? (
                <EmptyState title={t('supplierRequests.empty')} description={t('supplierRequests.emptyDescription')} />
            ) : (
                <>
                    {/* Desktop (≥ 1280px): table. "Créée par" only on wide screens (it is in Détails). */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface xl:block">
                        <table className="erp-table w-full min-w-[60rem] text-start text-sm">
                            <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                                <tr className="[&>th]:px-2.5 [&>th]:py-3 [&>th]:font-medium [&>th]:whitespace-nowrap">
                                    <th>{t('common.status')}</th>
                                    <th className="w-[15rem]">{t('supplierRequests.article')}</th>
                                    <th>{t('supplierRequests.quotation')}</th>
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
                                            <Link href={`/quotations/${row.quotation.id}`} className="font-medium text-ink underline-offset-2 hover:underline">
                                                {quotationLabel(row)}
                                            </Link>
                                            {!row.quotation.is_draft && <span className="block text-[11px] text-ink-faint">{t('supplierRequests.frozen')}</span>}
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
                            <li key={row.id} className="flex min-w-0 flex-col rounded-card border border-line bg-surface p-3 text-sm">
                                <div className="flex items-center justify-between gap-2">
                                    <RowStatusBadge status={row.status} label={t(statusKey(row.status))} />
                                    <span className="whitespace-nowrap text-xs text-ink-faint">{row.requested_at ? formatDate(row.requested_at) : '—'}</span>
                                </div>
                                <div className="mt-2">
                                    <Article row={row} />
                                </div>
                                <dl className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2 text-xs text-ink-muted">
                                    <div className="min-w-0">
                                        <dt>{t('supplierRequests.quotation')}</dt>
                                        <dd>
                                            <Link href={`/quotations/${row.quotation.id}`} className="font-medium text-ink underline">
                                                {quotationLabel(row)}
                                            </Link>
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

                    <Pagination links={requests.links} />
                </>
            )}

            {recording && (
                <RecordResultDialog
                    quotationId={recording.quotation.id}
                    request={toSourcingRequest(recording)}
                    onClose={() => setRecording(null)}
                    only={RELOAD}
                />
            )}

            <Dialog
                open={detail !== null}
                onClose={() => setDetail(null)}
                title={t('supplierRequests.detailTitle')}
                description={detail ? quotationLabel(detail) : undefined}
                footer={
                    <div className="flex justify-end gap-2">
                        {detail && (
                            <Link
                                href={`/quotations/${detail.quotation.id}`}
                                className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                            >
                                {t('supplierRequests.openQuotation')}
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
