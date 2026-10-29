import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { formatDateTime, formatQuantity } from '@/utils/format';
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';

type Status = 'preparing' | 'ready' | 'handed_to_carrier' | 'in_transit' | 'delivered' | 'closed' | 'cancelled';
type Action = 'mark_ready' | 'hand_to_carrier' | 'mark_in_transit' | 'mark_delivered' | 'close' | 'cancel' | 'edit_details';
type Line = { line_id: number; article: string; sku: string | null; catalog: boolean; quantity: string; planned: string; shipped: string; delivered: string; remaining: string; shippable: string };
type Shipment = {
    id: string;
    sequence: number;
    status: Status;
    channel: 'carrier' | 'direct';
    workflow_mode: 'simple' | 'advanced';
    items: { line_id: number; article: string; quantity: string }[];
    carrier: { id: number; name: string; contact: string | null; phone: string | null } | null;
    tracking_number: string | null;
    carrier_reference: string | null;
    package_count: number | null;
    expected_delivery_at: string | null;
    note: string | null;
    handed_to_carrier_at: string | null;
    handed_by: string | null;
    in_transit_at: string | null;
    delivered_at: string | null;
    closed_at: string | null;
    cancelled_at: string | null;
    attachments: { id: string; name: string; category: string; mime_type: string; size_bytes: number; url: string }[];
    actions: Action[];
};
export type FulfillmentPayload = {
    mode: 'simple' | 'advanced';
    state: 'unfulfilled' | 'partially_fulfilled' | 'fulfilled';
    delivery_state: 'not_delivered' | 'partially_delivered' | 'delivered';
    awaiting_replenishment: boolean;
    lines: Line[];
    fulfillments: Shipment[];
    actions: ('hand_to_carrier' | 'start_preparation')[];
    can: { manage: boolean; dispatch: boolean; deliver: boolean; attach: boolean };
};

const STATUS_TONE: Record<Status, string> = {
    preparing: 'bg-warning-soft text-warning',
    ready: 'bg-info-soft text-info',
    handed_to_carrier: 'bg-primary/10 text-primary',
    in_transit: 'bg-primary/10 text-primary',
    delivered: 'bg-success-soft text-success',
    closed: 'bg-raised text-ink-muted',
    cancelled: 'bg-raised text-ink-faint',
};
const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';
/** datetime-local value (viewer's local time) → ISO instant, so the server stores the right UTC time. */
const toIso = (value: unknown) => (typeof value === 'string' && value !== '' ? new Date(value).toISOString() : value);
const plain = (value: string) => String(Number(value));
const nowLocal = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
};

/** "Expédition & Livraison": shipments of a confirmed order. Rules are server-side (App\Actions\Fulfillment). */
export default function FulfillmentCard({ orderId, data }: { orderId: number; data: FulfillmentPayload }) {
    const { t } = useI18n();
    const k = (key: string) => t(`fulfillment.${key}` as TranslationKey);
    const [dialog, setDialog] = useState<{ kind: 'dispatch' | 'prepare' | 'deliver' | 'cancel' | 'edit'; shipment: Shipment | null } | null>(null);
    const base = `/sales/orders/${orderId}/fulfillments`;
    const post = (shipment: Shipment, path: string) => router.post(`${base}/${shipment.id}/${path}`, { expected_status: shipment.status }, { preserveScroll: true });
    const shippable = data.lines.filter((line) => Number(line.shippable) > 0);

    return (
        <section className="mb-6 rounded-card border border-line bg-surface p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{k('title')}</h2>
                    <p className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                        <span className="font-medium text-ink">{k(`state.${data.state}`)}</span>
                        {data.delivery_state !== 'not_delivered' && <span className="text-ink-muted">· {k(`delivery.${data.delivery_state}`)}</span>}
                        <span className="rounded-full bg-raised px-2 py-0.5 text-xs text-ink-muted">{k(`mode.${data.mode}`)}</span>
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {data.actions.includes('hand_to_carrier') && <Button size="sm" onClick={() => setDialog({ kind: 'dispatch', shipment: null })}>{k('handToCarrier')}</Button>}
                    {data.actions.includes('start_preparation') && <Button size="sm" onClick={() => setDialog({ kind: 'prepare', shipment: null })}>{k('startPreparation')}</Button>}
                </div>
            </div>
            {data.awaiting_replenishment && <p className="mt-2 text-xs text-warning">{k('awaitingReplenishment')}</p>}

            <div className="mt-4 overflow-x-auto rounded-field border border-line">
                <table className="w-full min-w-[520px] text-start text-sm">
                    <thead className="bg-raised text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-3 py-2 text-start font-medium">{k('article')}</th>
                            <th className="px-3 py-2 text-end font-medium">{k('ordered')}</th>
                            <th className="px-3 py-2 text-end font-medium">{k('shipped')}</th>
                            <th className="px-3 py-2 text-end font-medium">{k('delivered')}</th>
                            <th className="px-3 py-2 text-end font-medium">{k('remaining')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {data.lines.map((line) => (
                            <tr key={line.line_id}>
                                <td className="px-3 py-2 text-ink">{line.article}</td>
                                <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(line.quantity)}</td>
                                <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(line.shipped)}</td>
                                <td className="px-3 py-2 text-end tabular-nums">{formatQuantity(line.delivered)}</td>
                                <td className={`px-3 py-2 text-end font-medium tabular-nums ${Number(line.remaining) > 0 ? 'text-warning' : 'text-ink-muted'}`}>{formatQuantity(line.remaining)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {data.fulfillments.length === 0 ? (
                <p className="mt-4 text-sm text-ink-muted">{k('none')}</p>
            ) : (
                <ol className="mt-4 space-y-3">
                    {data.fulfillments.map((shipment) => (
                        <ShipmentRow key={shipment.id} shipment={shipment} base={base} canAttach={data.can.attach} canRemove={data.can.manage}
                            onAction={(action) => {
                                if (action === 'mark_ready') post(shipment, 'ready');
                                else if (action === 'mark_in_transit') post(shipment, 'in-transit');
                                else if (action === 'close') post(shipment, 'close');
                                else if (action === 'hand_to_carrier') setDialog({ kind: 'dispatch', shipment });
                                else if (action === 'mark_delivered') setDialog({ kind: 'deliver', shipment });
                                else if (action === 'cancel') setDialog({ kind: 'cancel', shipment });
                                else if (action === 'edit_details') setDialog({ kind: 'edit', shipment });
                            }}
                        />
                    ))}
                </ol>
            )}

            {dialog?.kind === 'dispatch' && <DispatchDialog base={base} shipment={dialog.shipment} lines={shippable} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'prepare' && <PrepareDialog base={base} lines={shippable} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'deliver' && dialog.shipment && <DeliverDialog base={base} shipment={dialog.shipment} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'cancel' && dialog.shipment && <CancelDialog base={base} shipment={dialog.shipment} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'edit' && dialog.shipment && <EditDialog base={base} shipment={dialog.shipment} onClose={() => setDialog(null)} />}
        </section>
    );
}

function ShipmentRow({ shipment, base, canAttach, canRemove, onAction }: { shipment: Shipment; base: string; canAttach: boolean; canRemove: boolean; onAction: (action: Action) => void }) {
    const { t } = useI18n();
    const k = (key: string) => t(`fulfillment.${key}` as TranslationKey);
    const upload = useForm<{ files: File[] }>({ files: [] });
    const actionLabels: Record<Action, string> = {
        mark_ready: k('markReady'), hand_to_carrier: k('handToCarrier'), mark_in_transit: k('markInTransit'), mark_delivered: k('markDelivered'),
        close: k('close'), cancel: k('cancel'), edit_details: k('editDetails'),
    };
    const primary: Action[] = ['mark_ready', 'hand_to_carrier', 'mark_in_transit', 'mark_delivered', 'close'];

    return (
        <li className="rounded-field border border-line bg-raised p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 font-medium text-ink">
                        {k('shipment')} {shipment.sequence}
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_TONE[shipment.status]}`}>
                            {shipment.channel === 'direct' && shipment.status === 'delivered' ? k('status.handed_directly') : k(`status.${shipment.status}`)}
                        </span>
                    </p>
                    <p className="mt-0.5 text-xs text-ink-muted">{shipment.items.map((item) => `${formatQuantity(item.quantity)} × ${item.article}`).join(' · ')}</p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {shipment.actions.map((action) => (
                        <Button key={action} size="sm" variant={primary.includes(action) ? 'primary' : 'secondary'} onClick={() => onAction(action)}>{actionLabels[action]}</Button>
                    ))}
                </div>
            </div>
            {(shipment.carrier || shipment.tracking_number || shipment.carrier_reference || shipment.handed_to_carrier_at || shipment.delivered_at || shipment.note) && (
                <dl className="mt-2 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                    {shipment.carrier && <Fact label={k('carrier')}>{shipment.carrier.name}{shipment.carrier.phone ? ` · ${shipment.carrier.phone}` : ''}</Fact>}
                    {shipment.tracking_number && <Fact label={k('trackingNumber')}><bdi>{shipment.tracking_number}</bdi></Fact>}
                    {shipment.carrier_reference && <Fact label={k('carrierReference')}><bdi>{shipment.carrier_reference}</bdi></Fact>}
                    {shipment.package_count !== null && <Fact label={k('packageCount')}>{shipment.package_count}</Fact>}
                    {shipment.handed_to_carrier_at && <Fact label={k('handedAt')}>{formatDateTime(shipment.handed_to_carrier_at)}{shipment.handed_by ? ` · ${shipment.handed_by}` : ''}</Fact>}
                    {shipment.expected_delivery_at && <Fact label={k('expectedDelivery')}>{formatDateTime(shipment.expected_delivery_at)}</Fact>}
                    {shipment.in_transit_at && <Fact label={k('inTransitAt')}>{formatDateTime(shipment.in_transit_at)}</Fact>}
                    {shipment.delivered_at && <Fact label={k('deliveredAt')}>{formatDateTime(shipment.delivered_at)}</Fact>}
                    {shipment.note && <Fact label={k('note')} wide>{shipment.note}</Fact>}
                </dl>
            )}
            {(shipment.attachments.length > 0 || (canAttach && shipment.channel === 'carrier' && shipment.status !== 'cancelled')) && (
                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-ink-faint">{k('proofs')} :</span>
                    {shipment.attachments.map((attachment) => (
                        <span key={attachment.id} className="inline-flex items-center gap-1 rounded border border-line bg-surface px-2 py-0.5">
                            <a href={attachment.url} className="text-ink underline">{attachment.name}</a>
                            {canRemove && (
                                <button type="button" aria-label={k('removeProof')} className="text-ink-faint hover:text-danger"
                                    onClick={() => window.confirm(k('removeProofConfirm')) && router.delete(`${base}/${shipment.id}/attachments/${attachment.id}`, { preserveScroll: true })}>×</button>
                            )}
                        </span>
                    ))}
                    {canAttach && shipment.channel === 'carrier' && shipment.status !== 'cancelled' && (
                        <label className="cursor-pointer text-ink underline">
                            {upload.processing ? t('common.loading') : k('addProof')}
                            <input type="file" multiple accept={ACCEPT} className="sr-only" onChange={(event) => {
                                const files = Array.from(event.target.files ?? []);
                                if (files.length === 0) return;
                                upload.transform(() => ({ files, category: 'shipping_proof' }));
                                upload.post(`${base}/${shipment.id}/attachments`, { preserveScroll: true, forceFormData: true });
                            }} />
                        </label>
                    )}
                    {Object.values(upload.errors)[0] && <span className="text-danger">{Object.values(upload.errors)[0]}</span>}
                </div>
            )}
        </li>
    );
}

function Fact({ label, children, wide = false }: { label: string; children: ReactNode; wide?: boolean }) {
    return (
        <div className={`min-w-0 ${wide ? 'sm:col-span-2' : ''}`}>
            <dt className="inline text-ink-faint">{label} : </dt>
            <dd className="inline break-words text-ink">{children}</dd>
        </div>
    );
}

function Quantities({ lines, value, onChange, errors }: { lines: Line[]; value: Record<number, string>; onChange: (next: Record<number, string>) => void; errors: Record<string, string> }) {
    const { t } = useI18n();
    const k = (key: string) => t(`fulfillment.${key}` as TranslationKey);

    return (
        <fieldset>
            <legend className="mb-1.5 text-xs font-medium uppercase tracking-wide text-ink-faint">{k('quantities')}</legend>
            <div className="divide-y divide-line rounded-field border border-line">
                {lines.map((line) => (
                    <label key={line.line_id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span className="min-w-0">
                            <span className="block truncate text-ink">{line.article}</span>
                            <span className="block text-xs text-ink-faint">{k('shippableNow')} : {formatQuantity(line.shippable)}</span>
                            {errors[`items.${line.line_id}`] && <span className="block text-xs text-danger">{errors[`items.${line.line_id}`]}</span>}
                        </span>
                        <input inputMode="decimal" value={value[line.line_id] ?? ''} onChange={(event) => onChange({ ...value, [line.line_id]: event.target.value })}
                            className="control-input w-24 text-end tabular-nums" aria-label={`${k('quantity')} — ${line.article}`} />
                    </label>
                ))}
            </div>
            {errors.items && <p className="mt-1 text-xs text-danger">{errors.items}</p>}
        </fieldset>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <label className="block min-w-0 text-sm">
            <span className="mb-1.5 block text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-danger">{error}</span>}
        </label>
    );
}

function DispatchDialog({ base, shipment, lines, onClose }: { base: string; shipment: Shipment | null; lines: Line[]; onClose: () => void }) {
    const { t } = useI18n();
    const k = (key: string) => t(`fulfillment.${key}` as TranslationKey);
    const operationId = useMemo(() => crypto.randomUUID(), []);
    const [carrierSearch, setCarrierSearch] = useState('');
    const [carriers, setCarriers] = useState<{ id: number; name: string; contact: string | null; phone: string | null }[]>([]);
    type DispatchForm = {
        items: Record<number, string>;
        carrier_contact_id: string;
        tracking_number: string;
        carrier_reference: string;
        handed_at: string;
        package_count: string;
        note: string;
        proofs: File[];
    };
    const form = useForm<DispatchForm>({
        items: Object.fromEntries(lines.map((line) => [line.line_id, plain(line.shippable)])),
        carrier_contact_id: '', tracking_number: '', carrier_reference: '', handed_at: nowLocal(), package_count: '', note: '', proofs: [],
    });
    const errors = form.errors as Record<string, string>;

    useEffect(() => {
        const timer = window.setTimeout(() => {
            axios.get(`${base}/carrier-options`, { params: { search: carrierSearch || undefined } }).then(({ data }) => setCarriers(data.data));
        }, 250);
        return () => window.clearTimeout(timer);
    }, [base, carrierSearch]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            items: shipment ? undefined : data.items,
            client_operation_id: shipment ? undefined : operationId,
            expected_status: shipment?.status,
            package_count: data.package_count === '' ? null : data.package_count,
            handed_at: toIso(data.handed_at),
        }));
        form.post(shipment ? `${base}/${shipment.id}/hand-to-carrier` : `${base}/hand-to-carrier`, { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={() => !form.processing && onClose()} title={k('handToCarrier')} description={k('handToCarrierHint')} dismissible={!form.processing}
            footer={<div className="flex justify-end gap-2"><Button variant="secondary" type="button" onClick={onClose} disabled={form.processing}>{t('common.cancel')}</Button><Button type="submit" form="fulfillment-dispatch" loading={form.processing}>{k('confirmHandToCarrier')}</Button></div>}>
            <form id="fulfillment-dispatch" onSubmit={submit} className="space-y-5 px-4 py-5 sm:px-6">
                {shipment ? (
                    <p className="text-sm text-ink-muted">{shipment.items.map((item) => `${formatQuantity(item.quantity)} × ${item.article}`).join(' · ')}</p>
                ) : (
                    <Quantities lines={lines} value={form.data.items} onChange={(next) => form.setData('items', next)} errors={errors} />
                )}
                <Field label={k('carrier')} error={errors.carrier_contact_id}>
                    <input value={carrierSearch} onChange={(event) => setCarrierSearch(event.target.value)} placeholder={k('searchCarrier')} className="control-input mb-2 w-full" />
                    <select value={form.data.carrier_contact_id} onChange={(event) => form.setData('carrier_contact_id', event.target.value)} className="control-input w-full" required>
                        <option value="">{k('chooseCarrier')}</option>
                        {carriers.map((carrier) => <option key={carrier.id} value={carrier.id}>{[carrier.name, carrier.contact, carrier.phone].filter(Boolean).join(' · ')}</option>)}
                    </select>
                    {carriers.length === 0 && <span className="mt-1 block text-xs text-ink-faint">{k('noCarrierHint')}</span>}
                </Field>
                <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                    <Field label={k('trackingNumber')} error={errors.tracking_number}><input value={form.data.tracking_number} onChange={(event) => form.setData('tracking_number', event.target.value)} className="control-input w-full" maxLength={128} /></Field>
                    <Field label={k('carrierReference')} error={errors.carrier_reference}><input value={form.data.carrier_reference} onChange={(event) => form.setData('carrier_reference', event.target.value)} className="control-input w-full" maxLength={128} /></Field>
                    <Field label={k('handedAt')} error={errors.handed_at}><input type="datetime-local" required value={form.data.handed_at} onChange={(event) => form.setData('handed_at', event.target.value)} className="control-input w-full" /></Field>
                    <Field label={k('packageCount')} error={errors.package_count}><input type="number" min={1} value={form.data.package_count} onChange={(event) => form.setData('package_count', event.target.value)} className="control-input w-full" /></Field>
                </div>
                <Field label={k('proofs')} error={errors.proofs ?? Object.entries(errors).find(([key]) => key.startsWith('proofs.'))?.[1]}>
                    <input type="file" multiple accept={ACCEPT} onChange={(event) => form.setData('proofs', Array.from(event.target.files ?? []))} className="block w-full text-sm" />
                    <span className="mt-1 block text-xs text-ink-faint">{k('proofsHint')}</span>
                </Field>
                <Field label={k('note')} error={errors.note}><textarea value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} rows={2} className="control-input w-full" maxLength={2000} /></Field>
                {(errors.order || errors.mode || errors.status) && <p className="text-xs text-danger">{errors.order ?? errors.mode ?? errors.status}</p>}
            </form>
        </Dialog>
    );
}

function PrepareDialog({ base, lines, onClose }: { base: string; lines: Line[]; onClose: () => void }) {
    const { t } = useI18n();
    const k = (key: string) => t(`fulfillment.${key}` as TranslationKey);
    const operationId = useMemo(() => crypto.randomUUID(), []);
    const form = useForm<{ items: Record<number, string>; note: string; client_operation_id: string }>({
        items: Object.fromEntries(lines.map((line) => [line.line_id, plain(line.shippable)])), note: '', client_operation_id: operationId,
    });
    const errors = form.errors as Record<string, string>;

    return (
        <Dialog open onClose={() => !form.processing && onClose()} title={k('startPreparation')} dismissible={!form.processing}
            footer={<div className="flex justify-end gap-2"><Button variant="secondary" type="button" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="fulfillment-prepare" loading={form.processing}>{k('startPreparation')}</Button></div>}>
            <form id="fulfillment-prepare" onSubmit={(event) => { event.preventDefault(); form.post(`${base}/preparation`, { preserveScroll: true, onSuccess: onClose }); }} className="space-y-5 px-4 py-5 sm:px-6">
                <Quantities lines={lines} value={form.data.items} onChange={(next) => form.setData('items', next)} errors={errors} />
                <Field label={k('note')} error={errors.note}><textarea value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} rows={2} className="control-input w-full" maxLength={2000} /></Field>
                {(errors.order || errors.mode) && <p className="text-xs text-danger">{errors.order ?? errors.mode}</p>}
            </form>
        </Dialog>
    );
}

function DeliverDialog({ base, shipment, onClose }: { base: string; shipment: Shipment; onClose: () => void }) {
    const { t } = useI18n();
    const k = (key: string) => t(`fulfillment.${key}` as TranslationKey);
    const form = useForm({ delivered_at: nowLocal(), expected_status: shipment.status });

    return (
        <Dialog open onClose={() => !form.processing && onClose()} title={k('markDelivered')} description={`${k('shipment')} ${shipment.sequence}`} dismissible={!form.processing}
            footer={<div className="flex justify-end gap-2"><Button variant="secondary" type="button" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="fulfillment-deliver" loading={form.processing}>{k('markDelivered')}</Button></div>}>
            <form id="fulfillment-deliver" onSubmit={(event) => { event.preventDefault(); form.transform((data) => ({ ...data, delivered_at: toIso(data.delivered_at) })); form.post(`${base}/${shipment.id}/delivered`, { preserveScroll: true, onSuccess: onClose }); }} className="space-y-4 px-4 py-5 sm:px-6">
                <Field label={k('deliveredAt')} error={form.errors.delivered_at ?? (form.errors as Record<string, string>).status}>
                    <input type="datetime-local" required value={form.data.delivered_at} onChange={(event) => form.setData('delivered_at', event.target.value)} className="control-input w-full" />
                </Field>
            </form>
        </Dialog>
    );
}

function CancelDialog({ base, shipment, onClose }: { base: string; shipment: Shipment; onClose: () => void }) {
    const { t } = useI18n();
    const k = (key: string) => t(`fulfillment.${key}` as TranslationKey);
    const form = useForm({ reason: '', expected_status: shipment.status });

    return (
        <Dialog open danger onClose={() => !form.processing && onClose()} title={k('cancel')} description={k('cancelHint')} dismissible={!form.processing}
            footer={<div className="flex justify-end gap-2"><Button variant="secondary" type="button" onClick={onClose}>{t('common.cancel')}</Button><Button variant="danger" type="submit" form="fulfillment-cancel" loading={form.processing}>{k('cancel')}</Button></div>}>
            <form id="fulfillment-cancel" onSubmit={(event) => { event.preventDefault(); form.post(`${base}/${shipment.id}/cancel`, { preserveScroll: true, onSuccess: onClose }); }} className="space-y-4 px-4 py-5 sm:px-6">
                <Field label={k('reason')} error={form.errors.reason ?? (form.errors as Record<string, string>).status}>
                    <textarea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} rows={2} className="control-input w-full" maxLength={500} />
                </Field>
            </form>
        </Dialog>
    );
}

function EditDialog({ base, shipment, onClose }: { base: string; shipment: Shipment; onClose: () => void }) {
    const { t } = useI18n();
    const k = (key: string) => t(`fulfillment.${key}` as TranslationKey);
    const form = useForm({
        tracking_number: shipment.tracking_number ?? '', carrier_reference: shipment.carrier_reference ?? '',
        package_count: shipment.package_count?.toString() ?? '', note: shipment.note ?? '', expected_status: shipment.status,
    });
    const errors = form.errors as Record<string, string>;

    return (
        <Dialog open onClose={() => !form.processing && onClose()} title={k('editDetails')} description={`${k('shipment')} ${shipment.sequence}`} dismissible={!form.processing}
            footer={<div className="flex justify-end gap-2"><Button variant="secondary" type="button" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="fulfillment-edit" loading={form.processing}>{t('common.save')}</Button></div>}>
            <form id="fulfillment-edit" onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({ ...data, package_count: data.package_count === '' ? null : data.package_count }));
                form.patch(`${base}/${shipment.id}`, { preserveScroll: true, onSuccess: onClose });
            }} className="space-y-4 px-4 py-5 sm:px-6">
                <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                    <Field label={k('trackingNumber')} error={errors.tracking_number}><input value={form.data.tracking_number} onChange={(event) => form.setData('tracking_number', event.target.value)} className="control-input w-full" maxLength={128} /></Field>
                    <Field label={k('carrierReference')} error={errors.carrier_reference}><input value={form.data.carrier_reference} onChange={(event) => form.setData('carrier_reference', event.target.value)} className="control-input w-full" maxLength={128} /></Field>
                    <Field label={k('packageCount')} error={errors.package_count}><input type="number" min={1} value={form.data.package_count} onChange={(event) => form.setData('package_count', event.target.value)} className="control-input w-full" /></Field>
                </div>
                <Field label={k('note')} error={errors.note}><textarea value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} rows={2} className="control-input w-full" maxLength={2000} /></Field>
                {errors.status && <p className="text-xs text-danger">{errors.status}</p>}
            </form>
        </Dialog>
    );
}
