import PageHeader from '@/components/ui/PageHeader';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDateTime, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Warehouse = { id: number; name: string; code: string };
type Line = {
    id: number;
    reason: string;
    reason_label: string;
    quantity: string;
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
type Props = { request: Req; members: Member[]; can: { manage: boolean; receive: boolean } };

const STATUS_LABEL: Record<string, string> = {
    requested: 'Demandée',
    preparing: 'En préparation',
    shipped: 'Expédiée',
    received: 'Réceptionnée',
    cancelled: 'Annulée',
};

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
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">Chauffeur / Livreur</span>
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
                        <option value="">Personne externe / saisie manuelle…</option>
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
                    placeholder="Nom du chauffeur"
                    className="w-full rounded-lg border px-3 py-2"
                />
            </label>
            <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">Téléphone</span>
                <input value={form.data.driver_phone} onChange={(e) => form.setData('driver_phone', e.target.value)} className="w-full rounded-lg border px-3 py-2" />
            </label>
            <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">Véhicule</span>
                <input value={form.data.vehicle} onChange={(e) => form.setData('vehicle', e.target.value)} placeholder="optionnel" className="w-full rounded-lg border px-3 py-2" />
            </label>
            <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">Immatriculation</span>
                <input value={form.data.vehicle_registration} onChange={(e) => form.setData('vehicle_registration', e.target.value)} placeholder="optionnel" className="w-full rounded-lg border px-3 py-2" />
            </label>
            <label className="text-sm sm:col-span-2">
                <span className="mb-1 block font-medium text-slate-700">Note</span>
                <input value={form.data.shipping_note} onChange={(e) => form.setData('shipping_note', e.target.value)} placeholder="optionnel" className="w-full rounded-lg border px-3 py-2" />
            </label>
        </div>
    );
}

export default function TransferRequestShow({ request, members, can }: Props) {
    const act = (verb: string) => router.post(`/inventory/transfer-requests/${request.id}/${verb}`, {}, { preserveScroll: true });
    const cancelForm = useForm({ reason: '' });
    const [shipOpen, setShipOpen] = useState(false);

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
    const byReason = request.lines.reduce<Record<string, number>>((acc, l) => {
        acc[l.reason_label] = (acc[l.reason_label] ?? 0) + Number(l.quantity);
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
                        ← Demandes de transfert
                    </Link>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium">{STATUS_LABEL[request.status] ?? request.status}</span>
                {request.sales_order && (
                    <Link href={`/sales/orders/${request.sales_order.id}`} className="text-sm text-slate-600 hover:underline">
                        Commande {request.sales_order.order_number}
                    </Link>
                )}
                {request.stock_transfer && (
                    <Link href={`/inventory/transfers/${request.stock_transfer.id}`} className="text-sm text-slate-600 hover:underline">
                        Mouvement {request.stock_transfer.transfer_number}
                    </Link>
                )}
                {request.bon_de_sortie_available && (
                    <span className="ml-auto flex gap-2">
                        <a
                            href={`/inventory/transfer-requests/${request.id}/bon`}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-slate-50"
                        >
                            Voir le bon de sortie
                        </a>
                        <a
                            href={`/inventory/transfer-requests/${request.id}/bon/download`}
                            className="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-slate-50"
                        >
                            Télécharger
                        </a>
                    </span>
                )}
            </div>

            {can.manage && (request.status === 'requested' || request.status === 'preparing') && (
                <div className="mb-6 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {request.status === 'requested' && (
                            <button onClick={() => act('prepare')} className="rounded-lg border px-3 py-2 text-sm font-medium hover:bg-slate-50">
                                Préparer
                            </button>
                        )}
                        {request.status === 'preparing' && !shipOpen && (
                            <button onClick={() => setShipOpen(true)} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                                Expédier
                            </button>
                        )}
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                cancelForm.post(`/inventory/transfer-requests/${request.id}/cancel`, { preserveScroll: true });
                            }}
                            className="flex items-center gap-2"
                        >
                            <input
                                value={cancelForm.data.reason}
                                onChange={(e) => cancelForm.setData('reason', e.target.value)}
                                placeholder="Motif (facultatif)"
                                className="rounded-lg border px-3 py-2 text-sm"
                            />
                            <button className="rounded-lg border border-rose-200 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50">
                                Annuler
                            </button>
                        </form>
                    </div>

                    {request.status === 'preparing' && !shipOpen && (
                        <details className="rounded-xl border bg-white p-4">
                            <summary className="cursor-pointer text-sm font-medium text-slate-700">Renseigner le chauffeur (avant expédition)</summary>
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
                                    Enregistrer le chauffeur
                                </button>
                            </form>
                        </details>
                    )}

                    {request.status === 'preparing' && shipOpen && (
                        <div className="rounded-xl border bg-white p-4">
                            <h3 className="text-sm font-semibold text-slate-800">Expédier le transfert</h3>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    shipForm.transform((d) => ({ ...d, driver_user_id: d.driver_user_id || null }));
                                    shipForm.post(`/inventory/transfer-requests/${request.id}/ship`, {
                                        preserveScroll: true,
                                        onSuccess: () => setShipOpen(false),
                                    });
                                }}
                                className="mt-3 space-y-3"
                            >
                                <DriverFields form={shipForm} members={members} />
                                <div className="flex gap-2">
                                    <button
                                        disabled={shipForm.processing}
                                        className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                    >
                                        Confirmer l'expédition
                                    </button>
                                    <button type="button" onClick={() => setShipOpen(false)} className="rounded-lg border px-3 py-2 text-sm text-slate-600">
                                        Annuler
                                    </button>
                                </div>
                                <p className="text-xs text-slate-500">Le bon de sortie peut être imprimé avant le départ. Le stock bougera à la réception.</p>
                            </form>
                        </div>
                    )}
                </div>
            )}

            {/* Destination view of an in-transit shipment (§14) */}
            {(request.status === 'shipped' || request.status === 'received') && (
                <div className="mb-6 rounded-xl border bg-white p-4 text-sm">
                    <h3 className="mb-2 font-semibold text-slate-800">{request.status === 'shipped' ? 'Colis en route' : 'Transfert réceptionné'}</h3>
                    <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                        <div className="flex justify-between sm:block">
                            <dt className="text-slate-500">Chauffeur / Livreur</dt>
                            <dd>{request.driver_name ?? '—'}{request.driver_phone ? ` · ${request.driver_phone}` : ''}</dd>
                        </div>
                        {hasDriver && (request.vehicle || request.vehicle_registration) && (
                            <div className="flex justify-between sm:block">
                                <dt className="text-slate-500">Véhicule</dt>
                                <dd>{[request.vehicle, request.vehicle_registration && `(${request.vehicle_registration})`].filter(Boolean).join(' ') || '—'}</dd>
                            </div>
                        )}
                        <div className="flex justify-between sm:block">
                            <dt className="text-slate-500">Expédié le</dt>
                            <dd>{request.shipped_at ? formatDateTime(request.shipped_at) : '—'}</dd>
                        </div>
                        <div className="flex justify-between sm:block">
                            <dt className="text-slate-500">Depuis</dt>
                            <dd>{request.source?.name ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between sm:block">
                            <dt className="text-slate-500">Articles</dt>
                            <dd>
                                {formatQuantity(total)} unité(s) · {request.lines.length} référence(s)
                            </dd>
                        </div>
                    </dl>
                    {request.shipping_note && <p className="mt-2 text-slate-500">Note : {request.shipping_note}</p>}
                    {can.manage && request.status === 'shipped' && (
                        <details className="mt-3">
                            <summary className="cursor-pointer text-xs font-medium text-slate-500">Corriger le chauffeur</summary>
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
                                    Enregistrer
                                </button>
                            </form>
                        </details>
                    )}
                    {can.receive && request.status === 'shipped' && (
                        <button
                            onClick={() => act('receive')}
                            className="mt-3 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        >
                            Confirmer la réception
                        </button>
                    )}
                </div>
            )}

            {request.status === 'shipped' && (
                <p className="mb-6 text-sm text-slate-500">Une demande expédiée ne peut plus être annulée sans procédure de retour.</p>
            )}

            <div className="overflow-hidden rounded-2xl border bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="p-3">Article</th>
                            <th className="p-3">Motif</th>
                            <th className="p-3 text-right">Quantité</th>
                        </tr>
                    </thead>
                    <tbody>
                        {request.lines.map((l) => (
                            <tr key={l.id} className="border-t">
                                <td className="p-3">
                                    <span className="font-medium">{l.product_name ?? l.variant_label ?? '—'}</span>
                                    <span className="block text-xs text-slate-500">{l.reference ?? l.sku ?? ''}</span>
                                </td>
                                <td className="p-3">{l.reason_label}</td>
                                <td className="p-3 text-right tabular-nums">{formatQuantity(l.quantity)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t bg-slate-50 font-medium">
                            <td className="p-3" colSpan={2}>
                                {Object.entries(byReason)
                                    .map(([label, qty]) => `${formatQuantity(qty)} — ${label}`)
                                    .join('  ·  ')}
                            </td>
                            <td className="p-3 text-right tabular-nums">Total {formatQuantity(total)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <dl className="mt-6 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                {[
                    ['Demandée', request.requested_at, request.requested_by?.name ?? 'Système'],
                    ['Préparée', request.prepared_at, request.prepared_by?.name],
                    ['Expédiée', request.shipped_at, request.shipped_by?.name],
                    ['Réceptionnée', request.received_at, request.received_by?.name],
                ].map(([label, at, who]) => (
                    <div key={label as string} className="rounded-xl border bg-white p-3">
                        <dt className="text-xs uppercase tracking-wide text-slate-400">{label}</dt>
                        <dd className="mt-1">{at ? formatDateTime(at as string) : '—'}</dd>
                        {who && <dd className="text-xs text-slate-500">{who as string}</dd>}
                    </div>
                ))}
            </dl>
            {request.cancellation_reason && <p className="mt-3 text-sm text-rose-700">Annulée : {request.cancellation_reason}</p>}
        </InventoryLayout>
    );
}
