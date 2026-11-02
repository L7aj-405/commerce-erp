import { Notice, RouteLabel, SectionCard } from '@/components/inventory/TransferRequestUi';
import { ButtonLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDateTime, formatInteger, formatQuantity } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type Transfer = {
    id: number;
    transfer_number: string;
    transferred_at: string;
    status: string;
    reason: string | null;
    product_count: number;
    unit_count: string;
    source_warehouse: { id: number; name: string; code: string };
    destination_warehouse: { id: number; name: string; code: string };
    performed_by: { name: string } | null;
    lines: Array<{ id: number; quantity: string; product_variant: { id: number; label: string | null; sku: string; product: { id: number; name: string } } }>;
};
type Origin = { id: number; request_number: string; sales_order_id: number | null } | null;

export default function TransferShow({ transfer, origin = null }: { transfer: Transfer; origin?: Origin }) {
    const { t } = useI18n();
    return (
        <InventoryLayout>
            <Head title={transfer.transfer_number} />
            <PageHeader
                title={transfer.transfer_number}
                description={`${transfer.source_warehouse.name} → ${transfer.destination_warehouse.name}`}
                actions={<><ButtonLink href="/inventory/transfers/create">{t('inventory.transfers.new')}</ButtonLink><ButtonLink href="/inventory/stock" variant="secondary">{t('inventory.warehouses.viewStock')}</ButtonLink></>}
            />

            <div className="space-y-4">
                <Notice tone="success" title={t('inventory.trq.movementDone')}>
                    {t('inventory.trq.movementDoneBody')}
                    {origin && (
                        <>
                            {' '}
                            {t('inventory.trq.movementFromRequest')}{' '}
                            <Link href={`/inventory/transfer-requests/${origin.id}`} className="font-medium text-ink underline-offset-2 hover:underline"><bdi>{origin.request_number}</bdi></Link>
                        </>
                    )}
                </Notice>

                <div className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                    {[
                        [t('common.date'), formatDateTime(transfer.transferred_at)],
                        [t('inventory.transfers.performedBy'), transfer.performed_by?.name ?? t('inventory.system')],
                        [t('inventory.transfers.products'), formatInteger(transfer.product_count)],
                        [t('inventory.transfers.units'), formatQuantity(transfer.unit_count)],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-card border border-line bg-surface px-4 py-3 shadow-soft">
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p>
                            <p className="mt-1 truncate text-lg font-semibold tabular-nums text-ink">{value}</p>
                        </div>
                    ))}
                </div>

                <SectionCard
                    title={<RouteLabel source={transfer.source_warehouse} destination={transfer.destination_warehouse} compact />}
                    actions={<StatusBadge status="completed">{t('inventory.transfers.completed')}</StatusBadge>}
                    bodyClassName=""
                >
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[480px] text-start text-sm">
                            <thead className="border-b border-line bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th scope="col" className="px-4 py-2.5 text-start font-semibold">{t('inventory.product')}</th>
                                    <th scope="col" className="px-4 py-2.5 text-start font-semibold">SKU</th>
                                    <th scope="col" className="px-4 py-2.5 text-end font-semibold">{t('inventory.quantity')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {transfer.lines.map((line) => (
                                    <tr key={line.id}>
                                        <td className="px-4 py-2.5">
                                            <div className="font-medium text-ink">{line.product_variant.product.name}</div>
                                            <div className="text-xs text-ink-faint">{line.product_variant.label ?? t('inventory.mainVariant')}</div>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted"><bdi>{line.product_variant.sku}</bdi></td>
                                        <td className="px-4 py-2.5 text-end font-semibold tabular-nums text-ink">{formatQuantity(line.quantity)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t border-line bg-raised font-semibold">
                                    <td className="px-4 py-2.5" colSpan={2}>{t('inventory.trq.totalUnits')}</td>
                                    <td className="px-4 py-2.5 text-end tabular-nums">{formatQuantity(transfer.unit_count)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </SectionCard>

                {transfer.reason && (
                    <SectionCard title={t('inventory.reason')}>
                        <p className="text-sm text-ink-muted">{transfer.reason}</p>
                    </SectionCard>
                )}
            </div>
        </InventoryLayout>
    );
}
