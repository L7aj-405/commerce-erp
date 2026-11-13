import {
    LifecycleProgress,
    Notice,
    PriorityBadge,
    SectionCard,
    TransferStatusBadge,
    TransferTypeBadge,
    useTransferLabels,
    type WarehouseRef,
} from '@/components/inventory/TransferRequestUi';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDateTime, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState, type ReactNode } from 'react';

type Line = {
    id: number;
    reason: string;
    reason_label: string;
    quantity: string;
    requested_quantity: string | null;
    reallocated_quantity: string | null;
    product_variant_id: number;
    protected: boolean;
    transferable: string | null;
    product_name: string | null;
    variant_label: string | null;
    sku: string | null;
    reference: string | null;
};
type Breakdown = {
    product_variant_id: number;
    product_name: string | null;
    variant_label: string | null;
    sku: string | null;
    reference: string | null;
    customer_quantity: string | null;
    covered_at_destination: string | null;
    /** Direct dispatch only: customer units leaving straight from their own warehouse. */
    direct_dispatch: string | null;
    customer_allocations: { warehouse_id: number; warehouse: string; quantity: string; reserved: string }[];
    reserved_at_source: string;
    for_customer: string;
    for_customer_other_sources: string;
    replenishment: string;
    reallocated: string;
    reallocated_from: string[];
    minimum_shortage: string | null;
    manual: string;
    total: string;
    other_active_incoming: string;
    minimum: string | null;
    destination_on_hand: string;
    destination_available: string;
    projected_after_fulfillment: string | null;
    source_on_hand: string;
    source_reserved: string;
    source_transferable: string | null;
    shortage: string;
};
type HistoryEntry = { id: number; event: string; actor: string | null; at: string | null; old: Record<string, unknown> | null; new: Record<string, unknown> | null };
type Member = { id: number; name: string; email: string | null };
type Req = {
    id: number;
    request_number: string;
    status: string;
    type: string;
    priority: string;
    created_at: string | null;
    cancellation_reason: string | null;
    note: string | null;
    lock_version: number;
    restored_at: string | null;
    restored_by: { name: string } | null;
    requested_at: string | null;
    prepared_at: string | null;
    shipped_at: string | null;
    received_at: string | null;
    cancelled_at: string | null;
    driver_name: string | null;
    driver_phone: string | null;
    driver_user_id: number | null;
    vehicle: string | null;
    vehicle_registration: string | null;
    shipping_note: string | null;
    bon_de_sortie_available: boolean;
    source: WarehouseRef;
    destination: WarehouseRef;
    sales_order: {
        id: number;
        order_number: string;
        status: string;
        fulfillment_status: string;
        customer: string | null;
        fulfillment_mode: 'pickup' | 'delivery' | null;
        fulfillment_origin: 'showroom' | 'direct' | null;
    } | null;
    /** Carries protected customer stock (ORDER_FULFILLMENT lines). */
    customer_transfer: boolean;
    stock_transfer: { id: number; transfer_number: string; transferred_at: string } | null;
    requested_by: { name: string } | null;
    prepared_by: { name: string } | null;
    shipped_by: { name: string } | null;
    received_by: { name: string } | null;
    lines: Line[];
};
type Restore = { eligible: boolean; blockers: string[] } | null;
type Props = { request: Req; breakdown: Breakdown[]; history: HistoryEntry[]; members: Member[]; restore: Restore; can: { manage: boolean; receive: boolean } };

type DriverForm = { driver_user_id: string; driver_name: string; driver_phone: string; vehicle: string; vehicle_registration: string; shipping_note: string };
type DriverFormLike = {
    data: DriverForm;
    processing: boolean;
    errors: Partial<Record<keyof DriverForm, string>>;
    setData: { (key: keyof DriverForm, value: string): void; (updater: (data: DriverForm) => DriverForm): void };
};

const inputClass = 'h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary focus-visible:ring-2 focus-visible:ring-primary/20';
const n = (v: string | number | null | undefined) => Number(v ?? 0);

function DriverFields({ form, members }: { form: DriverFormLike; members: Member[] }) {
    const { t } = useI18n();
    const field = (key: keyof DriverForm, label: string, placeholder?: string, wide = false) => (
        <label className={`block text-sm ${wide ? 'sm:col-span-2' : ''}`}>
            <span className="mb-1.5 block font-medium text-ink">{label}</span>
            <input value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} placeholder={placeholder} className={inputClass} />
            {form.errors[key] && <span className="mt-1 block text-xs text-danger">{form.errors[key]}</span>}
        </label>
    );
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm">
                <span className="mb-1.5 block font-medium text-ink">{t('inventory.requests.driver')}</span>
                {members.length > 0 && (
                    <select
                        value={form.data.driver_user_id}
                        onChange={(e) => {
                            const id = e.target.value;
                            const m = members.find((x) => String(x.id) === id);
                            form.setData((d) => ({ ...d, driver_user_id: id, driver_name: m ? m.name : d.driver_name }));
                        }}
                        className={`${inputClass} mb-2`}
                    >
                        <option value="">{t('inventory.requests.externalPerson')}</option>
                        {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                )}
                <input value={form.data.driver_name} onChange={(e) => form.setData('driver_name', e.target.value)} placeholder={t('inventory.requests.driverName')} className={inputClass} />
            </label>
            {field('driver_phone', t('customers.phone'))}
            {field('vehicle', t('inventory.requests.vehicle'), t('inventory.requests.optional'))}
            {field('vehicle_registration', t('inventory.requests.registration'), t('inventory.requests.optional'))}
            {field('shipping_note', t('inventory.requests.note'), t('inventory.requests.optional'), true)}
        </div>
    );
}

/** One "Description — Quantité" row of the per-product breakdown. */
/** "Obligatoire" / "Optionnel" chip — text, not colour alone. */
function NeedTag({ kind }: { kind: 'mandatory' | 'optional' }) {
    const { t } = useI18n();
    return kind === 'mandatory'
        ? <span className="ms-1.5 rounded border border-danger/25 bg-danger-soft px-1.5 py-px text-[11px] font-medium text-danger">{t('inventory.trq.mandatory')}</span>
        : <span className="ms-1.5 rounded border border-line-strong bg-surface px-1.5 py-px text-[11px] font-medium text-ink-muted">{t('inventory.trq.optional')}</span>;
}

function QtyRow({ label, value, hint, strong = false, tone, icon }: { label: ReactNode; value: ReactNode; hint?: ReactNode; strong?: boolean; tone?: 'muted' | 'danger' | 'success'; icon?: ReactNode }) {
    return (
        <div className={`flex items-baseline justify-between gap-3 py-1.5 ${strong ? 'mt-1 border-t border-line pt-2.5' : ''}`}>
            <dt className={`min-w-0 text-[13px] ${strong ? 'font-semibold text-ink' : tone === 'muted' ? 'text-ink-faint' : 'text-ink-muted'}`}>
                <span className="inline-flex items-center gap-1.5">{icon}{label}</span>
                {hint && <span className="block text-[11px] text-ink-faint">{hint}</span>}
            </dt>
            <dd className={`shrink-0 tabular-nums ${strong ? 'text-base font-bold text-ink' : tone === 'danger' ? 'font-semibold text-danger' : tone === 'success' ? 'font-semibold text-success' : 'font-semibold text-ink'}`} dir="ltr">
                {value}
            </dd>
        </div>
    );
}

export default function TransferRequestShow({ request, breakdown, history, members, restore, can }: Props) {
    const { t } = useI18n();
    const labels = useTransferLabels();
    const pageErrors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const [busy, setBusy] = useState<string | null>(null);
    const act = (verb: string, onSuccess?: () => void) => router.post(`/inventory/transfer-requests/${request.id}/${verb}`, {}, {
        preserveScroll: true,
        onStart: () => setBusy(verb),
        onFinish: () => setBusy(null),
        onSuccess,
    });

    const cancelForm = useForm({ reason: '' });
    const [cancelOpen, setCancelOpen] = useState(false);
    const [shipOpen, setShipOpen] = useState(false);
    const [receiveOpen, setReceiveOpen] = useState(false);
    const [driverOpen, setDriverOpen] = useState(false);
    const [reviewed, setReviewed] = useState(false);
    const [sent, setSent] = useState<Record<number, string>>(() => Object.fromEntries(request.lines.map((l) => [l.id, String(Number(l.quantity))])));

    const initialDriver: DriverForm = {
        driver_user_id: request.driver_user_id ? String(request.driver_user_id) : '',
        driver_name: request.driver_name ?? '',
        driver_phone: request.driver_phone ?? '',
        vehicle: request.vehicle ?? '',
        vehicle_registration: request.vehicle_registration ?? '',
        shipping_note: request.shipping_note ?? '',
    };
    const shipForm = useForm<DriverForm>(initialDriver);
    const driverForm = useForm<DriverForm>(initialDriver);
    const shipErrors = shipForm.errors as Record<string, string | undefined>;

    const total = request.lines.reduce((s, l) => s + n(l.quantity), 0);
    const forCustomer = request.lines.filter((l) => l.reason === 'order_fulfillment').reduce((s, l) => s + n(l.quantity), 0);
    const forReplenishment = request.lines.filter((l) => l.reason !== 'order_fulfillment').reduce((s, l) => s + n(l.quantity), 0);
    const sentTotal = request.lines.reduce((sum, l) => sum + (Number(sent[l.id]) || 0), 0);
    const reducedLines = request.lines.filter((l) => Number(sent[l.id]) < n(l.quantity));
    const totalShortage = breakdown.reduce((s, b) => s + n(b.shortage), 0);
    const pageErrorList = ['status', 'restore', 'reason', 'lock_version', 'lines'].map((k) => pageErrors[k]).filter(Boolean) as string[];
    const isOrder = request.sales_order !== null;
    // Order-linked but carrying replenishment only (direct dispatch order).
    const replenishmentOnlyForOrder = isOrder && !request.customer_transfer;
    const origin = request.sales_order?.fulfillment_origin ?? null;
    const linesByVariant = useMemo(() => {
        const map: Record<number, Line[]> = {};
        request.lines.forEach((l) => (map[l.product_variant_id] ??= []).push(l));
        return map;
    }, [request.lines]);
    const reallocatedFrom = useMemo(() => {
        const numbers = new Set<string>();
        history.forEach((h) => {
            if (h.event === 'transfer_request.replenishment_received_from_general' && typeof h.new?.request_number === 'string') numbers.add(h.new.request_number);
        });
        return [...numbers];
    }, [history]);

    const src = request.source?.name ?? '—';
    const dst = request.destination?.name ?? '—';

    // --- what should the employee do now? -----------------------------------
    type Step = { title: string; body: string; action?: ReactNode };
    const nextStep: Step | null = (() => {
        if (request.status === 'requested' && can.manage) {
            return {
                title: t('inventory.trq.nextPrepareTitle'),
                body: t('inventory.trq.nextPrepareBody').replace('{units}', formatQuantity(total)).replace('{source}', src),
                action: <Button type="button" onClick={() => act('prepare')} loading={busy === 'prepare'}>{t('inventory.trq.startPreparation')}</Button>,
            };
        }
        if (request.status === 'preparing' && can.manage) {
            return {
                title: t('inventory.trq.nextShipTitle'),
                body: t('inventory.trq.nextShipBody').replace('{destination}', dst),
                action: <Button type="button" onClick={() => { setReviewed(false); setShipOpen(true); }}>{t('inventory.requestShow.reviewAndShip')}</Button>,
            };
        }
        if (request.status === 'shipped' && can.receive) {
            return {
                title: t('inventory.trq.nextReceiveTitle'),
                body: t('inventory.trq.nextReceiveBody').replace('{destination}', dst),
                action: <Button type="button" onClick={() => setReceiveOpen(true)}>{t('inventory.requests.confirmReceipt')}</Button>,
            };
        }
        if (request.status === 'shipped') return { title: t('inventory.requests.inTransit'), body: t('inventory.trq.waitingReceipt').replace('{destination}', dst) };
        if (request.status === 'received') return { title: t('inventory.requests.receivedTransfer'), body: t('inventory.trq.receivedBody').replace('{destination}', dst) };
        return null;
    })();

    const eventLabel = (event: string) => {
        const key = `inventory.trq.ev.${event.replace('transfer_request.', '').replace('auto_replenishment.', 'auto_')}`;
        const value = t(key as Parameters<typeof t>[0]) as string | undefined;
        return value || event.replaceAll('_', ' ').replace('.', ' · ');
    };
    const eventDetail = (h: HistoryEntry): string | null => {
        const v = h.new ?? {};
        const pick = (k: string) => (v[k] === undefined || v[k] === null ? null : String(v[k]));
        if (h.event.includes('reallocated_to_order') || h.event.includes('received_from_general') || h.event.includes('yielded_to_order')) {
            const template = h.event.includes('received_from_general') ? 'inventory.trq.evReceivedDetail' : h.event.includes('yielded_to_order') ? 'inventory.trq.evYieldedDetail' : 'inventory.trq.evMoveDetail';
            return t(template)
                .replace('{qty}', formatQuantity(pick('moved_quantity') ?? 0))
                .replace('{from}', pick('request_number') ?? '—')
                .replace('{to}', pick('target_request_number') ?? '—')
                .replace('{order}', pick('sales_order_number') ?? '—')
                .replace('{old}', formatQuantity(String((h.old ?? {}).quantity ?? '0')))
                .replace('{new}', formatQuantity(pick('quantity') ?? 0));
        }
        if (pick('reason') && h.event.endsWith('cancelled')) return pick('reason');
        if (pick('quantity') && pick('product_variant_id')) return `${formatQuantity(pick('quantity') ?? 0)} u.`;
        return null;
    };

    return (
        <InventoryLayout>
            <Head title={request.request_number} />

            {/* ---------- Header ---------- */}
            <div className="mb-5">
                <Link href="/inventory/transfer-requests" className="inline-flex items-center gap-1 text-sm text-ink-muted hover:text-ink hover:underline">
                    <span aria-hidden="true" className="rtl:rotate-180">←</span> {t('inventory.requests.back')}
                </Link>
                <div className="mt-2 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight text-ink"><bdi>{request.request_number}</bdi></h1>
                            <TransferStatusBadge status={request.status} />
                            <TransferTypeBadge type={request.type} />
                            {request.priority === 'high' && <PriorityBadge priority="high" />}
                        </div>
                        <p className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-muted">
                            <span className="font-medium text-ink">{src}</span>
                            <span aria-hidden="true" className="inline-block rtl:-scale-x-100">→</span>
                            <span className="font-medium text-ink">{dst}</span>
                            {request.sales_order && (
                                <>
                                    <span aria-hidden="true" className="hidden text-ink-faint sm:inline">·</span>
                                    <span className="w-full sm:w-auto">
                                        {t('inventory.trq.forOrder')}{' '}
                                        <Link href={`/sales/orders/${request.sales_order.id}`} className="font-medium text-ink underline-offset-2 hover:underline"><bdi>{request.sales_order.order_number}</bdi></Link>
                                        {request.sales_order.customer ? ` — ${request.sales_order.customer}` : ''}
                                    </span>
                                    {origin && (
                                        <span className="inline-flex flex-wrap items-center gap-1.5">
                                            <span className="rounded-full bg-raised px-2 py-0.5 text-xs font-medium text-ink">
                                                {request.sales_order.fulfillment_mode === 'delivery' ? t('inventory.trq.modeDelivery') : t('inventory.trq.modePickup')}
                                            </span>
                                            <span className="rounded-full bg-sage px-2 py-0.5 text-xs font-medium text-ink">
                                                {origin === 'direct' ? t('inventory.trq.originDirect') : t('inventory.trq.originShowroom').replace('{warehouse}', dst)}
                                            </span>
                                        </span>
                                    )}
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {can.manage && request.status === 'requested' && (
                            <ButtonLink href={`/inventory/transfer-requests/${request.id}/edit`} variant="secondary">{t('inventory.requestShow.edit')}</ButtonLink>
                        )}
                        {request.bon_de_sortie_available && (
                            <>
                                <a href={`/inventory/transfer-requests/${request.id}/bon`} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised">
                                    {t('inventory.requests.viewExitSlip')}
                                </a>
                                <a href={`/inventory/transfer-requests/${request.id}/bon/download`} className="inline-flex min-h-10 items-center rounded-field px-3 text-sm font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink">
                                    {t('inventory.requests.download')}
                                </a>
                            </>
                        )}
                        {can.manage && (request.status === 'requested' || request.status === 'preparing') && (
                            <Button type="button" variant="danger" onClick={() => setCancelOpen(true)}>{t('inventory.requestShow.cancelRequest')}</Button>
                        )}
                    </div>
                </div>
            </div>

            <div className="space-y-4">
                {pageErrorList.length > 0 && !cancelOpen && !shipOpen && (
                    <Notice tone="danger" title={t('inventory.trq.actionFailed')}>
                        {pageErrorList.map((message) => <p key={message}>{message}</p>)}
                    </Notice>
                )}

                {/* ---------- Lifecycle + next step ---------- */}
                <SectionCard>
                    <LifecycleProgress
                        status={request.status}
                        events={{
                            requested: { at: request.requested_at, by: request.requested_by?.name ?? t('inventory.requests.system') },
                            preparing: { at: request.prepared_at, by: request.prepared_by?.name },
                            shipped: { at: request.shipped_at, by: request.shipped_by?.name },
                            received: { at: request.received_at, by: request.received_by?.name },
                        }}
                    />
                    {nextStep && (
                        <div className="mt-5 flex flex-col gap-3 rounded-field border border-primary/20 bg-sage/60 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0">
                                <p className="text-[13px] font-medium text-primary">{t('inventory.trq.nextStep')}</p>
                                <p className="mt-0.5 font-semibold text-ink">{nextStep.title}</p>
                                <p className="mt-0.5 text-sm text-ink-muted">{nextStep.body}</p>
                            </div>
                            {nextStep.action && <div className="shrink-0">{nextStep.action}</div>}
                        </div>
                    )}
                    {request.status === 'cancelled' && (
                        <div className="mt-5 rounded-field border border-line bg-raised p-4">
                            <p className="font-semibold text-ink">{t('inventory.requestShow.cancelledTitle')}</p>
                            <p className="mt-0.5 text-sm text-ink-muted">
                                {request.cancelled_at ? formatDateTime(request.cancelled_at) : '—'}
                                {request.cancellation_reason ? ` · ${request.cancellation_reason}` : ''}
                            </p>
                            {can.manage && restore && (
                                restore.eligible ? (
                                    <div className="mt-3 flex flex-wrap items-center gap-3">
                                        <Button type="button" variant="secondary" loading={busy === 'restore'} onClick={() => act('restore')}>{t('inventory.requestShow.restore')}</Button>
                                        <p className="text-xs text-ink-muted">{t('inventory.requestShow.restoreHelp')}</p>
                                    </div>
                                ) : (
                                    <div className="mt-3 rounded-field border border-warning/30 bg-warning-soft p-3 text-sm">
                                        <p className="font-medium text-warning">{t('inventory.requestShow.restoreBlocked')}</p>
                                        <ul className="mt-1 list-disc space-y-0.5 ps-5 text-ink-muted">
                                            {restore.blockers.map((b) => <li key={b}>{b}</li>)}
                                        </ul>
                                        <ButtonLink href={`/inventory/transfer-requests/create?from=${request.id}`} variant="secondary" size="sm" className="mt-3">{t('inventory.requestShow.createNew')}</ButtonLink>
                                    </div>
                                )
                            )}
                        </div>
                    )}
                    {request.restored_at && request.status !== 'cancelled' && (
                        <p className="mt-3 text-xs text-ink-muted">
                            {t('inventory.requestShow.restoredOn').replace('{date}', formatDateTime(request.restored_at)).replace('{name}', request.restored_by?.name ?? '—')}
                            {request.cancellation_reason ? ` · ${t('inventory.requestShow.previousCancel').replace('{reason}', request.cancellation_reason)}` : ''}
                        </p>
                    )}
                </SectionCard>

                {totalShortage > 0 && (request.status === 'requested' || request.status === 'preparing') && (
                    <Notice tone="warning" title={t('inventory.trq.shortageTitle').replace('{qty}', formatQuantity(totalShortage)).replace('{source}', src)}>
                        {t('inventory.trq.shortageBody')}
                    </Notice>
                )}

                {/* ---------- Summary metrics ---------- */}
                <div className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                    {[
                        { label: t('inventory.trq.metricTotal'), value: formatQuantity(total), helper: t('inventory.trq.productsValue').replace('{count}', String(breakdown.length)) },
                        { label: t('inventory.trq.metricCustomer'), value: formatQuantity(forCustomer), helper: replenishmentOnlyForOrder ? t('inventory.trq.noCustomerTransferHelp') : isOrder ? t('inventory.trq.protectedHelp') : '—', tone: forCustomer > 0 ? 'text-danger' : undefined },
                        { label: t('inventory.trq.metricReplenishment'), value: formatQuantity(forReplenishment), helper: request.status === 'requested' ? t('inventory.trq.adjustableHelp') : t('inventory.trq.lockedHelp') },
                        { label: t('inventory.trq.metricRoute'), value: request.source?.code ?? '—', helper: t('inventory.trq.toDestination').replace('{code}', request.destination?.code ?? '—') },
                    ].map((m) => (
                        <div key={m.label} className="surface-card px-4 py-3 shadow-soft">
                            <p className="text-[13px] font-medium text-ink-muted">{m.label}</p>
                            <p className={`mt-1 text-2xl font-semibold tabular-nums ${m.tone ?? 'text-ink'}`} dir="ltr">{m.value}</p>
                            <p className="mt-0.5 truncate text-xs text-ink-muted">{m.helper}</p>
                        </div>
                    ))}
                </div>

                {request.note && (
                    <Notice tone="info" title={t('inventory.requestForm.note')}>{request.note}</Notice>
                )}

                {replenishmentOnlyForOrder && (
                    <Notice tone="info" title={t('inventory.trq.replenishmentOnlyTitle')}>
                        {t('inventory.trq.replenishmentOnlyBody').replace('{order}', request.sales_order?.order_number ?? '').replace('{destination}', dst)}
                    </Notice>
                )}

                {isOrder && reallocatedFrom.length > 0 && (
                    <Notice tone="info" title={t('inventory.trq.reallocatedTitle')}>
                        {t('inventory.trq.reallocatedBody').replace('{requests}', reallocatedFrom.join(', '))}
                    </Notice>
                )}

                {/* ---------- Products & quantity breakdown ---------- */}
                <SectionCard
                    title={t('inventory.trq.productsTitle')}
                    description={isOrder ? t('inventory.trq.productsHelpOrder') : t('inventory.trq.productsHelp')}
                    bodyClassName="divide-y divide-line"
                >
                    {breakdown.map((b) => {
                        const lines = linesByVariant[b.product_variant_id] ?? [];
                        const adjusted = lines.filter((l) => l.requested_quantity !== null && n(l.requested_quantity) !== n(l.quantity));
                        const belowMinimum = b.minimum !== null && b.projected_after_fulfillment !== null && n(b.projected_after_fulfillment) < n(b.minimum);
                        return (
                            <article key={b.product_variant_id} className="grid gap-4 px-4 py-4 sm:px-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                                <div className="min-w-0">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="truncate font-semibold text-ink">{b.product_name ?? b.variant_label ?? '—'}</h3>
                                            <p className="truncate text-xs text-ink-faint">
                                                {b.variant_label && b.product_name ? `${b.variant_label} · ` : ''}
                                                <bdi>{b.reference ?? b.sku ?? ''}</bdi>
                                            </p>
                                        </div>
                                        <span className="shrink-0 rounded-md bg-raised px-2 py-1 text-sm font-bold tabular-nums text-ink" dir="ltr">{formatQuantity(b.total)}</span>
                                    </div>

                                    {isOrder && n(b.for_customer) > 0 && n(b.replenishment) > 0 && (
                                        <p className="mt-3 rounded-field border border-primary/15 bg-sage/50 px-3 py-2 text-[13px] text-ink">
                                            {t('inventory.trq.whyTotal')
                                                .replace('{customer}', formatQuantity(b.for_customer))
                                                .replace('{minimum}', formatQuantity(b.replenishment))
                                                .replace('{target}', formatQuantity(b.minimum ?? 0))
                                                .replaceAll('{total}', formatQuantity(b.total))}
                                        </p>
                                    )}
                                    <dl className="mt-3 rounded-field border border-line bg-raised/40 px-3 py-1.5">
                                        {b.customer_quantity !== null && (
                                            <QtyRow label={t('inventory.trq.bdCustomerOrder')} value={formatQuantity(b.customer_quantity)} tone="muted" />
                                        )}
                                        {b.covered_at_destination !== null && n(b.customer_quantity) > 0 && (
                                            <QtyRow label={t('inventory.trq.bdCoveredBy').replace('{warehouse}', dst)} value={formatQuantity(b.covered_at_destination)} tone="muted" />
                                        )}
                                        {origin === 'showroom' && n(b.reserved_at_source) > 0 && (
                                            <QtyRow
                                                tone="muted"
                                                label={t('inventory.trq.bdReservedAtSource').replace('{source}', src)}
                                                hint={t('inventory.trq.bdReservedAtSourceHint')}
                                                value={formatQuantity(b.reserved_at_source)}
                                            />
                                        )}
                                        {origin === 'direct' && b.customer_allocations
                                            .filter((a) => a.warehouse_id !== request.destination?.id && n(a.quantity) > 0)
                                            .map((a) => (
                                                <QtyRow
                                                    key={a.warehouse_id}
                                                    tone="muted"
                                                    label={t('inventory.trq.bdDirectFrom').replace('{warehouse}', a.warehouse)}
                                                    hint={t('inventory.trq.bdDirectFromHint')}
                                                    value={formatQuantity(a.quantity)}
                                                />
                                            ))}
                                        {n(b.for_customer_other_sources) > 0 && (
                                            <QtyRow label={t('inventory.trq.bdOtherSources')} value={formatQuantity(b.for_customer_other_sources)} tone="muted" />
                                        )}
                                        {n(b.for_customer) > 0 && (
                                            <QtyRow
                                                icon={<span aria-hidden="true" className="text-[11px]">🔒</span>}
                                                label={<>{t('inventory.trq.bdForCustomer').replace('{source}', src)}{isOrder && <NeedTag kind="mandatory" />}</>}
                                                hint={t('inventory.trq.bdProtected')}
                                                value={formatQuantity(b.for_customer)}
                                            />
                                        )}
                                        {n(b.replenishment) > 0 && (
                                            <QtyRow
                                                label={<>{isOrder ? t('inventory.trq.bdExtraMinimum') : t('inventory.trq.bdMinimum')}{isOrder && <NeedTag kind="optional" />}</>}
                                                hint={isOrder ? t('inventory.trq.bdOptionalHint') : undefined}
                                                value={formatQuantity(b.replenishment)}
                                            />
                                        )}
                                        {n(b.reallocated) > 0 && (
                                            <QtyRow
                                                tone="muted"
                                                label={t('inventory.trq.bdReallocatedRow').replace('{requests}', b.reallocated_from.join(', ') || '—')}
                                                hint={t('inventory.trq.bdReallocatedHint')}
                                                value={formatQuantity(b.reallocated)}
                                            />
                                        )}
                                        {n(b.manual) > 0 && <QtyRow label={t('inventory.trq.bdManual')} value={formatQuantity(b.manual)} />}
                                        <QtyRow strong label={t('inventory.trq.bdTotal').replace('{source}', src)} value={formatQuantity(b.total)} />
                                    </dl>

                                    {adjusted.length > 0 && (
                                        <p className="mt-2 text-xs text-warning">
                                            {adjusted.map((l) => t('inventory.trq.sentVsRequested')
                                                .replace('{reason}', labels.reason[l.reason] ?? l.reason_label)
                                                .replace('{requested}', formatQuantity(l.requested_quantity ?? 0))
                                                .replace('{sent}', formatQuantity(l.quantity))).join(' · ')}
                                        </p>
                                    )}
                                </div>

                                <div className="grid content-start gap-2 text-[13px] sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                    <div className="rounded-field border border-line px-3 py-2.5">
                                        <p className="text-[13px] font-medium text-ink-muted">{t('inventory.trq.ctxSource').replace('{source}', src)}</p>
                                        <dl className="mt-1">
                                            <QtyRow label={t('inventory.trq.ctxOnHand')} value={formatQuantity(b.source_on_hand)} />
                                            <QtyRow label={t('inventory.trq.ctxReserved')} value={formatQuantity(b.source_reserved)} tone="muted" />
                                            {b.source_transferable !== null && (
                                                <QtyRow
                                                    label={t('inventory.trq.ctxTransferable')}
                                                    hint={t('inventory.trq.ctxTransferableHelp')}
                                                    value={formatQuantity(b.source_transferable)}
                                                    tone={n(b.shortage) > 0 ? 'danger' : 'success'}
                                                />
                                            )}
                                        </dl>
                                        {n(b.shortage) > 0 && (
                                            <p className="mt-1.5 text-xs font-medium text-danger">{t('inventory.trq.lineShortage').replace('{qty}', formatQuantity(b.shortage))}</p>
                                        )}
                                    </div>
                                    <div className="rounded-field border border-line px-3 py-2.5">
                                        <p className="text-[13px] font-medium text-ink-muted">{t('inventory.trq.ctxDestination').replace('{destination}', dst)}</p>
                                        <dl className="mt-1">
                                            <QtyRow label={t('inventory.trq.ctxFreeNow')} hint={t('inventory.trq.ctxFreeNowHelp')} value={formatQuantity(b.destination_available)} />
                                            <QtyRow label={t('inventory.trq.ctxOtherIncoming')} value={formatQuantity(b.other_active_incoming)} tone="muted" />
                                            {b.projected_after_fulfillment !== null && (
                                                <QtyRow
                                                    label={isOrder ? t('inventory.trq.ctxProjectedOrder') : t('inventory.trq.ctxProjected')}
                                                    value={
                                                        <>
                                                            {formatQuantity(b.projected_after_fulfillment)}
                                                            {b.minimum !== null && n(b.minimum) > 0 && (
                                                                <span className="ms-1 text-xs font-medium text-ink-faint">/ min {formatQuantity(b.minimum)}</span>
                                                            )}
                                                        </>
                                                    }
                                                    tone={belowMinimum ? 'danger' : b.minimum !== null && n(b.minimum) > 0 ? 'success' : undefined}
                                                />
                                            )}
                                        </dl>
                                        {belowMinimum && (
                                            <p className="mt-1.5 text-xs font-medium text-warning">
                                                {n(b.minimum_shortage) > 0
                                                    ? t('inventory.trq.minimumShortage').replace('{qty}', formatQuantity(b.minimum_shortage ?? 0))
                                                    : t('inventory.trq.belowMinimum')}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                    {breakdown.length === 0 && <p className="px-5 py-8 text-center text-sm text-ink-muted">{t('inventory.trq.noLines')}</p>}
                </SectionCard>

                {/* ---------- Shipment details ---------- */}
                {(request.status === 'preparing' || request.status === 'shipped' || request.status === 'received') && (
                    <SectionCard
                        title={t('inventory.trq.shipmentTitle')}
                        actions={can.manage && (request.status === 'preparing' || request.status === 'shipped') ? (
                            <Button type="button" size="sm" variant="secondary" onClick={() => setDriverOpen(true)}>
                                {request.status === 'shipped' ? t('inventory.requests.correctDriver') : t('inventory.requests.saveDriver')}
                            </Button>
                        ) : undefined}
                    >
                        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div><dt className="text-xs text-ink-faint">{t('inventory.requests.driver')}</dt><dd className="font-medium text-ink">{request.driver_name ?? '—'}{request.driver_phone ? ` · ${request.driver_phone}` : ''}</dd></div>
                            <div><dt className="text-xs text-ink-faint">{t('inventory.requests.vehicle')}</dt><dd className="font-medium text-ink">{[request.vehicle, request.vehicle_registration && `(${request.vehicle_registration})`].filter(Boolean).join(' ') || '—'}</dd></div>
                            <div><dt className="text-xs text-ink-faint">{t('inventory.requests.shippedAt')}</dt><dd className="font-medium text-ink">{request.shipped_at ? formatDateTime(request.shipped_at) : '—'}</dd></div>
                            <div>
                                <dt className="text-xs text-ink-faint">{t('inventory.requests.movement')}</dt>
                                <dd className="font-medium text-ink">
                                    {request.stock_transfer
                                        ? <Link href={`/inventory/transfers/${request.stock_transfer.id}`} className="hover:underline"><bdi>{request.stock_transfer.transfer_number}</bdi></Link>
                                        : <span className="text-ink-muted">{t('inventory.trq.noMovementYet')}</span>}
                                </dd>
                            </div>
                        </dl>
                        {request.shipping_note && <p className="mt-3 text-sm text-ink-muted">{t('inventory.requests.noteValue').replace('{note}', request.shipping_note)}</p>}
                        {request.status === 'shipped' && <p className="mt-3 text-xs text-ink-faint">{t('inventory.requests.shippedNoCancel')}</p>}
                    </SectionCard>
                )}

                {/* ---------- History ---------- */}
                <SectionCard title={t('inventory.trq.historyTitle')} description={t('inventory.trq.historyHelp')}>
                    {history.length === 0 ? (
                        <p className="text-sm text-ink-muted">{t('inventory.trq.historyEmpty')}</p>
                    ) : (
                        <ol className="relative space-y-4 border-s border-line ps-5">
                            {history.map((h) => {
                                const detail = eventDetail(h);
                                return (
                                    <li key={h.id} className="relative">
                                        <span aria-hidden="true" className="absolute -start-[1.6rem] top-1.5 size-2.5 rounded-full border-2 border-surface bg-primary" />
                                        <p className="text-sm font-medium text-ink">{eventLabel(h.event)}</p>
                                        <p className="text-xs text-ink-faint">{h.at ? formatDateTime(h.at) : '—'}{h.actor ? ` · ${h.actor}` : ''}</p>
                                        {detail && <p className="mt-0.5 text-xs text-ink-muted">{detail}</p>}
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </SectionCard>
            </div>

            {/* ---------- Dialogs ---------- */}
            <Dialog
                open={cancelOpen}
                onClose={() => setCancelOpen(false)}
                title={t('inventory.requestShow.cancelTitle').replace('{number}', request.request_number)}
                description={t('inventory.requestShow.cancelHelp')}
                danger
                footer={
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={() => setCancelOpen(false)}>{t('common.close')}</Button>
                        <Button
                            type="button"
                            variant="danger"
                            disabled={cancelForm.data.reason.trim().length < 3}
                            loading={cancelForm.processing}
                            onClick={() => cancelForm.post(`/inventory/transfer-requests/${request.id}/cancel`, { preserveScroll: true, onSuccess: () => setCancelOpen(false) })}
                        >
                            {t('inventory.requestShow.confirmCancel')}
                        </Button>
                    </div>
                }
            >
                <div className="p-4 sm:p-6">
                    <label className="block text-sm">
                        <span className="mb-1.5 block font-medium text-ink">{t('inventory.requestShow.cancelReason')}</span>
                        <textarea
                            data-autofocus
                            rows={3}
                            required
                            value={cancelForm.data.reason}
                            onChange={(e) => cancelForm.setData('reason', e.target.value)}
                            className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm outline-none focus:border-primary"
                        />
                    </label>
                    {cancelForm.errors.reason && <p className="mt-2 text-sm text-danger" role="alert">{cancelForm.errors.reason}</p>}
                    {request.lines.some((l) => l.protected) && <p className="mt-3 text-xs text-ink-muted">{t('inventory.requestShow.orderProtectedHelp')}</p>}
                </div>
            </Dialog>

            <Dialog
                open={shipOpen}
                onClose={() => setShipOpen(false)}
                title={t('inventory.trq.shipDialogTitle').replace('{number}', request.request_number)}
                description={t('inventory.trq.shipDialogHelp').replace('{source}', src).replace('{destination}', dst)}
                footer={
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-ink-muted">{t('inventory.requests.shippingHelp')}</p>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row">
                            <Button type="button" variant="secondary" onClick={() => setShipOpen(false)}>{t('common.cancel')}</Button>
                            <Button
                                type="button"
                                disabled={!reviewed || sentTotal <= 0}
                                loading={shipForm.processing}
                                onClick={() => {
                                    shipForm.transform((d) => ({ ...d, driver_user_id: d.driver_user_id || null, lock_version: request.lock_version, lines: sent }));
                                    shipForm.post(`/inventory/transfer-requests/${request.id}/ship`, { preserveScroll: true, onSuccess: () => setShipOpen(false) });
                                }}
                            >
                                {t('inventory.trq.confirmShipUnits').replace('{units}', formatQuantity(sentTotal))}
                            </Button>
                        </div>
                    </div>
                }
            >
                <div className="space-y-5 p-4 sm:p-6">
                    <div>
                        <p className="mb-2 text-sm text-ink-muted">{t('inventory.requestShow.reviewHelp')}</p>
                        <div className="max-h-[min(22rem,45dvh)] overflow-y-auto rounded-field border border-line">
                            <table className="w-full text-start text-sm">
                                <thead className="sticky top-0 z-[1] bg-raised text-[13px] text-ink-muted font-medium">
                                    <tr>
                                        <th scope="col" className="px-3 py-2 text-start font-semibold">{t('inventory.requests.article')}</th>
                                        <th scope="col" className="px-3 py-2 text-end font-semibold">{t('inventory.requestShow.requestedQty')}</th>
                                        <th scope="col" className="px-3 py-2 text-end font-semibold">{t('inventory.requestShow.sentQty')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {request.lines.map((l) => {
                                        const err = shipErrors[`lines.${l.id}`] ?? shipErrors[`lines.${l.id}.quantity`];
                                        const reduced = Number(sent[l.id]) < n(l.quantity);
                                        return (
                                            <tr key={l.id} className={`align-top ${reduced ? 'bg-warning-soft/50' : ''}`}>
                                                <td className="px-3 py-2">
                                                    <p className="font-medium text-ink">{l.product_name ?? l.variant_label ?? '—'}</p>
                                                    <p className="text-xs text-ink-faint">
                                                        {labels.reason[l.reason] ?? l.reason_label}
                                                        {l.transferable !== null && !l.protected ? ` · ${t('inventory.picker.transferable')} ${formatQuantity(l.transferable)}` : ''}
                                                    </p>
                                                    {err && <p className="mt-1 text-xs text-danger" role="alert">{err}</p>}
                                                </td>
                                                <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(l.quantity)}</td>
                                                <td className="px-3 py-2 text-end">
                                                    {l.protected ? (
                                                        <span className="inline-flex items-center gap-1 font-semibold tabular-nums" title={t('inventory.picker.protected')}>
                                                            <span aria-hidden="true">🔒</span>{formatQuantity(l.quantity)}
                                                            <span className="sr-only">{t('inventory.picker.protected')}</span>
                                                        </span>
                                                    ) : (
                                                        <input
                                                            type="number"
                                                            min={0}
                                                            max={n(l.quantity)}
                                                            step="any"
                                                            inputMode="decimal"
                                                            value={sent[l.id] ?? ''}
                                                            onChange={(e) => { setSent((c) => ({ ...c, [l.id]: e.target.value })); setReviewed(false); }}
                                                            aria-label={`${t('inventory.requestShow.sentQty')} — ${l.product_name ?? ''}`}
                                                            className="h-9 w-24 rounded-field border border-line-strong bg-surface px-2 text-end text-sm tabular-nums outline-none focus:border-primary"
                                                        />
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-line bg-raised font-semibold">
                                        <td className="px-3 py-2">{t('inventory.trq.totalUnits')}</td>
                                        <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(total)}</td>
                                        <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(sentTotal)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        {shipErrors.lines && <p className="mt-2 text-sm text-danger" role="alert">{shipErrors.lines}</p>}
                        {shipErrors.lock_version && <p className="mt-2 text-sm text-danger" role="alert">{shipErrors.lock_version}</p>}
                        {reducedLines.length > 0 && (
                            <div className="mt-3">
                                <Notice tone="warning" title={t('inventory.trq.reducedTitle').replace('{count}', String(reducedLines.length))}>
                                    {t('inventory.trq.reducedBody')}
                                </Notice>
                            </div>
                        )}
                    </div>
                    <div>
                        <h3 className="mb-2 text-sm font-semibold text-ink">{t('inventory.trq.shipmentTitle')}</h3>
                        <DriverFields form={shipForm} members={members} />
                    </div>
                    <label className="flex items-start gap-2.5 rounded-field border border-line bg-raised p-3 text-sm text-ink">
                        <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} className="mt-0.5 size-4 accent-[var(--color-primary)]" />
                        <span>{t('inventory.requestShow.reviewConfirm').replace('{units}', formatQuantity(sentTotal))}</span>
                    </label>
                </div>
            </Dialog>

            <Dialog
                open={receiveOpen}
                onClose={() => setReceiveOpen(false)}
                title={t('inventory.trq.receiveDialogTitle').replace('{number}', request.request_number)}
                description={t('inventory.trq.receiveDialogHelp').replace('{destination}', dst)}
                footer={
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={() => setReceiveOpen(false)}>{t('common.cancel')}</Button>
                        <Button type="button" loading={busy === 'receive'} onClick={() => act('receive', () => setReceiveOpen(false))}>
                            {t('inventory.trq.confirmReceiveUnits').replace('{units}', formatQuantity(total))}
                        </Button>
                    </div>
                }
            >
                <div className="space-y-3 p-4 sm:p-6">
                    <ul className="divide-y divide-line rounded-field border border-line text-sm">
                        {request.lines.map((l) => (
                            <li key={l.id} className="flex items-center justify-between gap-3 px-3 py-2">
                                <span className="min-w-0">
                                    <span className="block truncate font-medium text-ink">{l.product_name ?? l.variant_label ?? '—'}</span>
                                    <span className="text-xs text-ink-faint">{labels.reason[l.reason] ?? l.reason_label}</span>
                                </span>
                                <span className="shrink-0 font-semibold tabular-nums" dir="ltr">{formatQuantity(l.quantity)}</span>
                            </li>
                        ))}
                    </ul>
                    {isOrder && forCustomer > 0 && <Notice tone="info">{t('inventory.trq.receiveCustomerHelp').replace('{qty}', formatQuantity(forCustomer)).replace('{order}', request.sales_order?.order_number ?? '')}</Notice>}
                </div>
            </Dialog>

            <Dialog
                open={driverOpen}
                onClose={() => setDriverOpen(false)}
                title={t('inventory.trq.shipmentTitle')}
                footer={
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={() => setDriverOpen(false)}>{t('common.cancel')}</Button>
                        <Button
                            type="button"
                            loading={driverForm.processing}
                            onClick={() => {
                                driverForm.transform((d) => ({ ...d, driver_user_id: d.driver_user_id || null }));
                                driverForm.patch(`/inventory/transfer-requests/${request.id}/driver`, { preserveScroll: true, onSuccess: () => setDriverOpen(false) });
                            }}
                        >
                            {t('common.save')}
                        </Button>
                    </div>
                }
            >
                <div className="p-4 sm:p-6"><DriverFields form={driverForm} members={members} /></div>
            </Dialog>
        </InventoryLayout>
    );
}
