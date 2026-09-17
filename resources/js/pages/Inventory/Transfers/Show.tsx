import { ButtonLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDateTime, formatInteger, formatQuantity } from '@/utils/format';
import { Head } from '@inertiajs/react';

type Transfer = {
    id: number;
    transfer_number: string;
    transferred_at: string;
    status: string;
    reason: string | null;
    product_count: number;
    unit_count: string;
    source_warehouse: { name: string; code: string };
    destination_warehouse: { name: string; code: string };
    performed_by: { name: string } | null;
    lines: Array<{ id: number; quantity: string; product_variant: { id: number; label: string | null; sku: string; product: { id: number; name: string } } }>;
};

export default function TransferShow({ transfer }: { transfer: Transfer }) {
    return (
        <InventoryLayout>
            <Head title={transfer.transfer_number} />
            <PageHeader
                title={transfer.transfer_number}
                description={`${transfer.source_warehouse.name} → ${transfer.destination_warehouse.name}`}
                actions={<><ButtonLink href="/inventory/transfers/create">Nouveau transfert</ButtonLink><ButtonLink href="/inventory/stock" variant="secondary">Voir le stock</ButtonLink></>}
            />

            <section className="mb-6 grid gap-4 rounded-2xl border bg-white p-5 md:grid-cols-4">
                <div>
                    <p className="text-sm text-slate-500">Date</p>
                    <p className="mt-1 font-semibold">{formatDateTime(transfer.transferred_at)}</p>
                </div>
                <div>
                    <p className="text-sm text-slate-500">De</p>
                    <p className="mt-1 font-semibold">{transfer.source_warehouse.name}</p>
                </div>
                <div>
                    <p className="text-sm text-slate-500">Vers</p>
                    <p className="mt-1 font-semibold">{transfer.destination_warehouse.name}</p>
                </div>
                <div>
                    <p className="text-sm text-slate-500">Effectue par</p>
                    <p className="mt-1 font-semibold">{transfer.performed_by?.name ?? 'Systeme'}</p>
                </div>
            </section>

            <section className="overflow-x-auto rounded-2xl border bg-white">
                <table className="w-full min-w-[480px] text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="p-3">Produit</th>
                            <th className="p-3">SKU</th>
                            <th className="p-3 text-right">Quantite</th>
                        </tr>
                    </thead>
                    <tbody>
                        {transfer.lines.map(line => (
                            <tr key={line.id} className="border-t">
                                <td className="p-3">
                                    <div className="font-semibold">{line.product_variant.product.name}</div>
                                    <div className="text-xs text-slate-500">{line.product_variant.label ?? 'Variante principale'}</div>
                                </td>
                                <td className="p-3">{line.product_variant.sku}</td>
                                <td className="p-3 text-right font-semibold">{formatQuantity(line.quantity)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>

            <section className="mt-6 ml-auto grid max-w-xl grid-cols-2 gap-2 rounded-2xl bg-slate-50 p-5 text-right">
                <span>Produits</span><strong>{formatInteger(transfer.product_count)}</strong>
                <span>Unites</span><strong>{formatQuantity(transfer.unit_count)}</strong>
                <span>Statut</span><strong>Effectue</strong>
            </section>

            {transfer.reason && (
                <section className="mt-6 rounded-2xl border bg-white p-5">
                    <h2 className="font-semibold">Motif</h2>
                    <p className="mt-2 text-sm text-slate-600">{transfer.reason}</p>
                </section>
            )}
        </InventoryLayout>
    );
}
