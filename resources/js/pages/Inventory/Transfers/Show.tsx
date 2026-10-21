import { ButtonLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
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
    const { t } = useI18n();
    return (
        <InventoryLayout>
            <Head title={transfer.transfer_number} />
            <PageHeader
                title={transfer.transfer_number}
                description={`${transfer.source_warehouse.name} → ${transfer.destination_warehouse.name}`}
                actions={<><ButtonLink href="/inventory/transfers/create">{t('inventory.transfers.new')}</ButtonLink><ButtonLink href="/inventory/stock" variant="secondary">{t('inventory.warehouses.viewStock')}</ButtonLink></>}
            />

            <section className="mb-6 grid gap-4 rounded-card border border-line bg-surface p-5 md:grid-cols-4">
                <div>
                    <p className="text-sm text-ink-muted">{t('common.date')}</p>
                    <p className="mt-1 font-semibold">{formatDateTime(transfer.transferred_at)}</p>
                </div>
                <div>
                    <p className="text-sm text-ink-muted">{t('inventory.source')}</p>
                    <p className="mt-1 font-semibold">{transfer.source_warehouse.name}</p>
                </div>
                <div>
                    <p className="text-sm text-ink-muted">{t('inventory.destination')}</p>
                    <p className="mt-1 font-semibold">{transfer.destination_warehouse.name}</p>
                </div>
                <div>
                    <p className="text-sm text-ink-muted">{t('inventory.transfers.performedBy')}</p>
                    <p className="mt-1 font-semibold">{transfer.performed_by?.name ?? t('inventory.system')}</p>
                </div>
            </section>

            <section className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="erp-table w-full min-w-[480px] text-start text-sm">
                    <thead className="bg-raised text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th className="p-3">{t('inventory.product')}</th>
                            <th className="p-3">SKU</th>
                            <th className="p-3 text-end">{t('inventory.quantity')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {transfer.lines.map(line => (
                            <tr key={line.id} className="border-t">
                                <td className="p-3">
                                    <div className="font-semibold">{line.product_variant.product.name}</div>
                                    <div className="text-xs text-ink-muted">{line.product_variant.label ?? t('inventory.mainVariant')}</div>
                                </td>
                                <td className="p-3"><bdi>{line.product_variant.sku}</bdi></td>
                                <td className="p-3 text-end font-semibold">{formatQuantity(line.quantity)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>

            <section className="mt-6 ms-auto grid max-w-xl grid-cols-2 gap-2 rounded-card bg-raised p-5 text-end">
                <span>{t('inventory.transfers.products')}</span><strong>{formatInteger(transfer.product_count)}</strong>
                <span>{t('inventory.transfers.units')}</span><strong>{formatQuantity(transfer.unit_count)}</strong>
                <span>{t('common.status')}</span><StatusBadge status="completed">{t('inventory.transfers.completed')}</StatusBadge>
            </section>

            {transfer.reason && (
                <section className="mt-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="font-semibold">{t('inventory.reason')}</h2>
                    <p className="mt-2 text-sm text-ink-muted">{transfer.reason}</p>
                </section>
            )}
        </InventoryLayout>
    );
}
