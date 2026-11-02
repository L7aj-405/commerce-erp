import { Button, ButtonLink } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDateTime, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Warehouse = { id: number; name: string; code: string };
type Line = {
    id: number;
    reason: string;
    reason_label: string;
    quantity: string;
    requested_quantity: string | null;
    protected: boolean;
    transferable: string | null;
    product_name: string | null;
    variant_label: string | null;
    sku: string | null;
    reference: string | null;
};
type Member = { id: number; name: string; email: string | null };
type Req = {
    id: number;
    request_number: string;
    status: string;
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
    source: Warehouse | null;
    destination: Warehouse | null;
    sales_order: { id: number; order_number: string; status: string; fulfillment_status: string } | null;
    stock_transfer: { id: number; transfer_number: string; transferred_at: string } | null;
    requested_by: { name: string } | null;
    prepared_by: { name: string } | null;
    shipped_by: { name: string } | null;
    received_by: { name: string } | null;
    lines: Line[];
};
type Restore = { eligible: boolean; blockers: string[] } | null;
type Props = { request: Req; members: Member[]; restore: Restore; can: { manage: boolean; receive: boolean } };

type DriverForm = {
    driver_user_id: string;
    driver_name: string;
    driver_phone: string;
    vehicle: string;
    vehicle_registration: string;
    shipping_note: string;
};
type DriverFormLike = {
    data: DriverForm;
    processing: boolean;
    setData: {
        (key: keyof DriverForm, value: string): void;
        (updater: (data: DriverForm) => DriverForm): void;
    };
};

function DriverFields({ form, members }: { form: DriverFormLike; members: Member[] }) {
    const { t } = useI18n();
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">{t('inventory.requests.driver')}</span>
                {members.length > 0 && (
                    <select
                        value={form.data.driver_user_id}
                        onChange={(e) => {
                            const id = e.target.value;
                            const m = members.find((x) => String(x.id) === id);
                            form.setData((d) => ({ ...d, driver_user_id: id, driver_name: m ? m.name : d.driver_name }));
                        }}
                        className="mb-1 w-full rounded-lg border px-3 py-2"
                    >
                        <option value="">{t('inventory.requests.externalPerson')}</option>
                        {members.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </select>
                )}
                <input
                    value={form.data.driver_name}
                    onChange={(e) => form.setData('driver_name', e.target.value)}
                    placeholder={t('inventory.requests.driverName')}
                    className="w-full rounded-lg border px-3 py-2"
                />
            </label>
            <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">{t('customers.phone')}</span>
                <input value={form.data.driver_phone} onChange={(e) => form.setData('driver_phone', e.target.value)} className="w-full rounded-lg border px-3 py-2" />
            </label>
            <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">{t('inventory.requests.vehicle')}</span>
                <input value={form.data.vehicle} onChange={(e) => form.setData('vehicle', e.target.value)} placeholder={t('inventory.requests.optional')} className="w-full rounded-lg border px-3 py-2" />
            </label>
            <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">{t('inventory.requests.registration')}</span>
                <input value={form.data.vehicle_registration} onChange={(e) => form.setData('vehicle_registration', e.target.value)} placeholder={t('inventory.requests.optional')} className="w-full rounded-lg border px-3 py-2" />
            </label>
            <label className="text-sm sm:col-span-2">
                <span className="mb-1 block font-medium text-slate-700">{t('inventory.requests.note')}</span>
                <input value={form.data.shipping_note} onChange={(e) => form.setData('shipping_note', e.target.value)} placeholder={t('inventory.requests.optional')} className="w-full rounded-lg border px-3 py-2" />
            </label>
        </div>
    );
}

export default function TransferRequestShow({ request, members, restore, can }: Props) {
    const { t } = useI18n();
    const pageErrors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const [busy, setBusy] = useState<string | null>(null);
    const act = (verb: string) => router.post(`/inventory/transfer-requests/${request.id}/${verb}`, {}, {
        preserveScroll: true,
        onStart: () => setBusy(verb),
        onFinish: () => setBusy(null),
    });
    const cancelForm = useForm({ reason: '' });
    const [cancelOpen, setCancelOpen] = useState(false);
    const [shipOpen, setShipOpen] = useState(false);
    const [reviewed, setReviewed] = useState(false);
    const [sent, setSent] = useState<Record<number, string>>(() => Object.fromEntries(request.lines.map((l) => [l.id, String(Number(l.quantity))])));
    const sentTotal = request.lines.reduce((sum, l) => sum + (Number(sent[l.id]) || 0), 0);
    const reviewChanged = request.lines.some((l) => Number(sent[l.id]) !== Number(l.quantity));
    const pageErrorList = ['status', 'restore', 'reason', 'lock_version', 'lines'].map((k) => pageErrors[k]).filter(Boolean) as string[];

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

    const total = request.lines.reduce((s, l) => s + Number(l.quantity), 0);
    const statusLabels: Record<string, string> = {
        requested: t('inventory.requests.requested'),
        preparing: t('inventory.requests.preparing'),
        shipped: t('inventory.requests.shipped'),
        received: t('inventory.requests.received'),
        cancelled: t('inventory.requests.cancelled'),
    };
    const reasonLabels: Record<string, string> = {
        order_fulfillment: t('inventory.requests.orderReason'),
        minimum_replenishment: t('inventory.requests.replenishmentReason'),
        manual: t('inventory.requests.manualReason'),
    };
    const byReason = request.lines.reduce<Record<string, number>>((acc, l) => {
        acc[l.reason] = (acc[l.reason] ?? 0) + Number(l.quantity);
        return acc;
    }, {});
    const hasDriver = Boolean(request.driver_name || request.vehicle || request.vehicle_registration);

    return (
        <InventoryLayout>
            <Head title={request.request_number} />
            <PageHeader
                title={request.request_number}
                description={`${request.source?.name ?? '—'} → ${request.destination?.name ?? '—'}`}
                actions={
                    <Link href="/inventory/transfer-requests" className="text-sm text-slate-500 hover:underline">
                        <span aria-hidden="true">←</span> {t('inventory.requests.back')}
                    </Link>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium">{statusLabels[request.status] ?? request.status}</span>
                {request.sales_order && (
                    <Link href={`/sales/orders/${request.sales_order.id}`} className="text-sm text-slate-600 hover:underline">
                        {t('inventory.requests.order')} <bdi>{request.sales_order.order_number}</bdi>
                    </Link>
                )}
                {request.stock_transfer && (
                    <Link href={`/inventory/transfers/${request.stock_transfer.id}`} className="text-sm text-slate-600 hover:underline">
                        {t('inventory.requests.movement')} <bdi>{request.stock_transfer.transfer_number}</bdi>
                    </Link>
                )}
                {request.bon_de_sortie_available && (
                    <span className="ms-auto flex gap-2">
                        <a
                            href={`/inventory/transfer-requests/${request.id}/bon`}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-slate-50"
                        >
                            {t('inventory.requests.viewExitSlip')}
                        </a>
                        <a
                            href={`/inventory/transfer-requests/${request.id}/bon/download`}
                            className="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-slate-50"
                        >
                            {t('inventory.requests.download')}
                        </a>
                    </span>
                )}
            </div>

            {pageErrorList.length > 0 && !cancelOpen && (
                <div className="mb-4 rounded-card border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
                    {pageErrorList.map((message) => <p key={message}>{message}</p>)}
                </div>
            )}

            {request.note && (
                <p className="mb-4 rounded-card border border-line bg-raised px-4 py-3 text-sm text-ink-muted">
                    <span className="font-medium text-ink">{t('inventory.requestForm.note')} :</span> {request.note}
                </p>
            )}

            {request.status === 'cancelled' && (
                <section className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                    <h3 className="text-sm font-semibold text-ink">{t('inventory.requestShow.cancelledTitle')}</h3>
                    <p className="mt-1 text-sm text-ink-muted">
                        {request.cancelled_at ? formatDateTime(request.cancelled_at) : '—'}
                        {request.cancellation_reason ? ` · ${request.cancellation_reason}` : ''}
                    </p>
                    {can.manage && restore && (
                        restore.eligible ? (
                            <div className="mt-3 flex flex-wrap items-center gap-3">
                                <Button type="button" variant="secondary" loading={busy === 'restore'} onClick={() => act('restore')}>
                                    {t('inventory.requestShow.restore')}
                                </Button>
                                <p className="text-xs text-ink-muted">{t('inventory.requestShow.restoreHelp')}</p>
                            </div>
                        ) : (
                            <div className="mt-3 rounded-field border border-warning/30 bg-warning-soft p-3 text-sm">
                                <p className="font-medium text-warning">{t('inventory.requestShow.restoreBlocked')}</p>
                                <ul className="mt-1 list-disc space-y-0.5 ps-5 text-ink-muted">
                                    {restore.blockers.map((b) => <li key={b}>{b}</li>)}
                                </ul>
                                <ButtonLink href={`/inventory/transfer-requests/create?from=${request.id}`} variant="secondary" size="sm" className="mt-3">
                                    {t('inventory.requestShow.createNew')}
                                </ButtonLink>
                            </div>
                        )
                    )}
                </section>
            )}

            {request.restored_at && request.status !== 'cancelled' && (
                <p className="mb-4 text-xs text-ink-muted">
                    {t('inventory.requestShow.restoredOn').replace('{date}', formatDateTime(request.restored_at)).replace('{name}', request.restored_by?.name ?? '—')}
                    {request.cancellation_reason ? ` · ${t('inventory.requestShow.previousCancel').replace('{reason}', request.cancellation_reason)}` : ''}
                </p>
            )}

            {can.manage && (request.status === 'requested' || request.status === 'preparing') && (
                <div className="mb-6 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {request.status === 'requested' && (
                            <ButtonLink href={`/inventory/transfer-requests/${request.id}/edit`} variant="secondary">
                                {t('inventory.requestShow.edit')}
                            </ButtonLink>
                        )}
                        {request.status === 'requested' && (
                            <Button type="button" onClick={() => act('prepare')} loading={busy === 'prepare'}>
                                {t('inventory.requests.prepare')}
                            </Button>
                        )}
                        {request.status === 'preparing' && !shipOpen && (
                            <Button type="button" onClick={() => setShipOpen(true)}>
                                {t('inventory.requestShow.reviewAndShip')}
                            </Button>
                        )}
                        <Button type="button" variant="danger" onClick={() => setCancelOpen(true)}>
                            {t('inventory.requestShow.cancelRequest')}
                        </Button>
                    </div>

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
                            {cancelForm.errors.reason && <p className="mt-2 text-sm text-danger">{cancelForm.errors.reason}</p>}
                            {request.lines.some((l) => l.protected) && <p className="mt-3 text-xs text-ink-muted">{t('inventory.requestShow.orderProtectedHelp')}</p>}
                        </div>
                    </Dialog>

                    {request.status === 'preparing' && !shipOpen && (
                        <details className="rounded-xl border bg-white p-4">
                            <summary className="cursor-pointer text-sm font-medium text-slate-700">{t('inventory.requests.driverBeforeShip')}</summary>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    driverForm.transform((d) => ({ ...d, driver_user_id: d.driver_user_id || null }));
                                    driverForm.patch(`/inventory/transfer-requests/${request.id}/driver`, { preserveScroll: true });
                                }}
                                className="mt-3 space-y-3"
                            >
                                <DriverFields form={driverForm} members={members} />
                                <button disabled={driverForm.processing} className="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-slate-50 disabled:opacity-50">
                                    {t('inventory.requests.saveDriver')}
                                </button>
                            </form>
                        </details>
                    )}

                    {request.status === 'preparing' && shipOpen && (
                        <div className="rounded-xl border bg-white p-4">
                            <h3 className="text-sm font-semibold text-slate-800">{t('inventory.requests.shipTransfer')}</h3>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    shipForm.transform((d) => ({ ...d, driver_user_id: d.driver_user_id || null, lock_version: request.lock_version, lines: sent }));
                                    shipForm.post(`/inventory/transfer-requests/${request.id}/ship`, {
                                        preserveScroll: true,
                                        onSuccess: () => setShipOpen(false),
                                    });
                                }}
                                className="mt-3 space-y-4"
                            >
                                <div>
                                    <p className="mb-2 text-sm text-ink-muted">{t('inventory.requestShow.reviewHelp')}</p>
                                    <div className="max-h-[22rem] overflow-y-auto rounded-field border border-line">
                                        <table className="w-full text-start text-sm">
                                            <thead className="sticky top-0 bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                                <tr>
                                                    <th className="px-3 py-2">{t('inventory.requests.article')}</th>
                                                    <th className="px-3 py-2 text-end">{t('inventory.requestShow.requestedQty')}</th>
                                                    <th className="px-3 py-2 text-end">{t('inventory.requestShow.sentQty')}</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-line">
                                                {request.lines.map((l) => {
                                                    const err = (shipForm.errors as Record<string, string | undefined>)[`lines.${l.id}`] ?? (shipForm.errors as Record<string, string | undefined>)[`lines.${l.id}.quantity`];
                                                    return (
                                                        <tr key={l.id} className="align-top">
                                                            <td className="px-3 py-2">
                                                                <p className="font-medium text-ink">{l.product_name ?? l.variant_label ?? '—'}</p>
                                                                <p className="text-xs text-ink-faint">
                                                                    {reasonLabels[l.reason] ?? l.reason_label}
                                                                    {l.transferable !== null && !l.protected ? ` · ${t('inventory.picker.transferable')} ${formatQuantity(l.transferable)}` : ''}
                                                                </p>
                                                                {err && <p className="mt-1 text-xs text-danger">{err}</p>}
                                                            </td>
                                                            <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(l.quantity)}</td>
                                                            <td className="px-3 py-2 text-end">
                                                                {l.protected ? (
                                                                    <span className="font-semibold tabular-nums" title={t('inventory.picker.protected')}>🔒 {formatQuantity(l.quantity)}</span>
                                                                ) : (
                                                                    <input
                                                                        type="number"
                                                                        min={0}
                                                                        max={Number(l.quantity)}
                                                                        step="any"
                                                                        value={sent[l.id] ?? ''}
                                                                        onChange={(e) => { setSent((c) => ({ ...c, [l.id]: e.target.value })); setReviewed(false); }}
                                                                        aria-label={`${t('inventory.requestShow.sentQty')} — ${l.product_name ?? ''}`}
                                                                        className="h-9 w-24 rounded-field border border-line-strong bg-surface px-2 text-end text-sm tabular-nums"
                                                                    />
                                                                )}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                            <tfoot>
                                                <tr className="border-t border-line bg-raised font-medium">
                                                    <td className="px-3 py-2">{reviewChanged ? t('inventory.requestShow.adjusted') : ''}</td>
                                                    <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(total)}</td>
                                                    <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(sentTotal)}</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    {(shipForm.errors as Record<string, string | undefined>).lines && <p className="mt-2 text-sm text-danger">{(shipForm.errors as Record<string, string | undefined>).lines}</p>}
                                    <label className="mt-3 flex items-start gap-2 text-sm text-ink">
                                        <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} className="mt-0.5" />
                                        <span>{t('inventory.requestShow.reviewConfirm').replace('{units}', formatQuantity(sentTotal))}</span>
                                    </label>
                                </div>
                                <DriverFields form={shipForm} members={members} />
                                <div className="flex gap-2">
                                    <button
                                        disabled={shipForm.processing || !reviewed}
                                        className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                    >
                                        {t('inventory.requests.confirmShipping')}
                                    </button>
                                    <button type="button" onClick={() => setShipOpen(false)} className="rounded-lg border px-3 py-2 text-sm text-slate-600">
                                        {t('common.cancel')}
                                    </button>
                                </div>
                                <p className="text-xs text-slate-500">{t('inventory.requests.shippingHelp')}</p>
                            </form>
                        </div>
                    )}
                </div>
            )}

            {/* Destination view of an in-transit shipment (§14) */}
            {(request.status === 'shipped' || request.status === 'received') && (
                <div className="mb-6 rounded-xl border bg-white p-4 text-sm">
                    <h3 className="mb-2 font-semibold text-slate-800">{request.status === 'shipped' ? t('inventory.requests.inTransit') : t('inventory.requests.receivedTransfer')}</h3>
                    <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                        <div className="flex justify-between sm:block">
                            <dt className="text-slate-500">{t('inventory.requests.driver')}</dt>
                            <dd>{request.driver_name ?? '—'}{request.driver_phone ? ` · ${request.driver_phone}` : ''}</dd>
                        </div>
                        {hasDriver && (request.vehicle || request.vehicle_registration) && (
                            <div className="flex justify-between sm:block">
                                <dt className="text-slate-500">{t('inventory.requests.vehicle')}</dt>
                                <dd>{[request.vehicle, request.vehicle_registration && `(${request.vehicle_registration})`].filter(Boolean).join(' ') || '—'}</dd>
                            </div>
                        )}
                        <div className="flex justify-between sm:block">
                            <dt className="text-slate-500">{t('inventory.requests.shippedAt')}</dt>
                            <dd>{request.shipped_at ? formatDateTime(request.shipped_at) : '—'}</dd>
                        </div>
                        <div className="flex justify-between sm:block">
                            <dt className="text-slate-500">{t('inventory.requests.from')}</dt>
                            <dd>{request.source?.name ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between sm:block">
                            <dt className="text-slate-500">{t('inventory.requests.articles')}</dt>
                            <dd>
                                {t('inventory.requests.articleCount').replace('{units}', formatQuantity(total)).replace('{references}', String(request.lines.length))}
                            </dd>
                        </div>
                    </dl>
                    {request.shipping_note && <p className="mt-2 text-slate-500">{t('inventory.requests.noteValue').replace('{note}', request.shipping_note)}</p>}
                    {can.manage && request.status === 'shipped' && (
                        <details className="mt-3">
                            <summary className="cursor-pointer text-xs font-medium text-slate-500">{t('inventory.requests.correctDriver')}</summary>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    driverForm.transform((d) => ({ ...d, driver_user_id: d.driver_user_id || null }));
                                    driverForm.patch(`/inventory/transfer-requests/${request.id}/driver`, { preserveScroll: true });
                                }}
                                className="mt-2 space-y-3"
                            >
                                <DriverFields form={driverForm} members={members} />
                                <button disabled={driverForm.processing} className="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-slate-50 disabled:opacity-50">
                                    {t('common.save')}
                                </button>
                            </form>
                        </details>
                    )}
                    {can.receive && request.status === 'shipped' && (
                        <button
                            onClick={() => act('receive')}
                            className="mt-3 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        >
                            {t('inventory.requests.confirmReceipt')}
                        </button>
                    )}
                </div>
            )}

            {request.status === 'shipped' && (
                <p className="mb-6 text-sm text-slate-500">{t('inventory.requests.shippedNoCancel')}</p>
            )}

            <div className="overflow-x-auto rounded-2xl border bg-white">
                <table className="w-full min-w-[480px] text-start text-sm">
                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="p-3">{t('inventory.requests.article')}</th>
                            <th className="p-3">{t('inventory.requests.reason')}</th>
                            <th className="p-3 text-end">{t('inventory.requests.quantity')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {request.lines.map((l) => (
                            <tr key={l.id} className="border-t">
                                <td className="p-3">
                                    <span className="font-medium">{l.product_name ?? l.variant_label ?? '—'}</span>
                                    <bdi className="block text-xs text-slate-500">{l.reference ?? l.sku ?? ''}</bdi>
                                    {!l.protected && l.transferable !== null && Number(l.transferable) < Number(l.quantity) && (
                                        <span className="mt-1 block text-xs text-warning">
                                            {t('inventory.requestShow.sourceShort').replace('{qty}', formatQuantity(l.transferable))}
                                        </span>
                                    )}
                                </td>
                                <td className="p-3">{reasonLabels[l.reason] ?? l.reason_label}</td>
                                <td className="p-3 text-end tabular-nums">
                                    {l.requested_quantity && Number(l.requested_quantity) !== Number(l.quantity) ? (
                                        <span title={t('inventory.requestShow.requestedQty')}>
                                            <s className="me-1 text-ink-faint">{formatQuantity(l.requested_quantity)}</s>
                                            {formatQuantity(l.quantity)}
                                        </span>
                                    ) : formatQuantity(l.quantity)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t bg-slate-50 font-medium">
                            <td className="p-3" colSpan={2}>
                                {Object.entries(byReason)
                                    .map(([reason, qty]) => `${formatQuantity(qty)} — ${reasonLabels[reason] ?? reason}`)
                                    .join('  ·  ')}
                            </td>
                            <td className="p-3 text-end tabular-nums">{t('inventory.requests.total').replace('{total}', formatQuantity(total))}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <dl className="mt-6 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                {[
                    [t('inventory.requests.requested'), request.requested_at, request.requested_by?.name ?? t('inventory.requests.system')],
                    [t('inventory.requests.prepared'), request.prepared_at, request.prepared_by?.name],
                    [t('inventory.requests.shipped'), request.shipped_at, request.shipped_by?.name],
                    [t('inventory.requests.received'), request.received_at, request.received_by?.name],
                ].map(([label, at, who]) => (
                    <div key={label as string} className="rounded-xl border bg-white p-3">
                        <dt className="text-xs uppercase tracking-wide text-slate-400">{label}</dt>
                        <dd className="mt-1">{at ? formatDateTime(at as string) : '—'}</dd>
                        {who && <dd className="text-xs text-slate-500">{who as string}</dd>}
                    </div>
                ))}
            </dl>
        </InventoryLayout>
    );
}
