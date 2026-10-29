import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import { formatDate, formatMoney, formatQuantity } from '@/utils/format';
import { Link, router } from '@inertiajs/react';
import type { SupplierRelation } from './SupplierRelationDialog';

/**
 * Supplier catalog relations as a table. `side` decides the counterpart column:
 * the product page lists suppliers, the supplier page lists variants.
 */
export default function SupplierRelationTable({
    relations,
    side,
    canManage,
    onEdit,
}: {
    relations: SupplierRelation[];
    side: 'variant' | 'supplier';
    canManage: boolean;
    onEdit: (relation: SupplierRelation) => void;
}) {
    const { t } = useI18n();
    const patch = (relation: SupplierRelation, data: Record<string, string | boolean>) =>
        router.patch(`/procurement/supplier-catalog/${relation.id}`, data, { preserveScroll: true });

    return (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
            <table className="erp-table w-full min-w-[900px] text-start text-sm">
                <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th className="px-3 py-2 font-medium">{side === 'variant' ? t('supplierCatalog.supplier') : t('supplierCatalog.product')}</th>
                        <th className="px-3 py-2 font-medium">{t('supplierCatalog.supplierSku')}</th>
                        <th className="px-3 py-2 font-medium">{t('supplierCatalog.purchasePrice')}</th>
                        <th className="px-3 py-2 font-medium">{t('supplierCatalog.moq')}</th>
                        <th className="px-3 py-2 font-medium">{t('supplierCatalog.leadTime')}</th>
                        <th className="px-3 py-2 font-medium">{t('supplierCatalog.lastPurchase')}</th>
                        <th className="px-3 py-2 font-medium">{t('common.status')}</th>
                        {canManage && <th className="px-3 py-2" />}
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {relations.map((relation) => {
                        const last = relation.last_purchase;
                        return (
                            <tr key={relation.id} className={relation.status === 'inactive' ? 'text-ink-faint' : undefined}>
                                <td className="px-3 py-2">
                                    {side === 'variant' ? (
                                        <Link href={`/procurement/suppliers/${relation.supplier.id}`} className="font-medium text-ink hover:underline">{relation.supplier.name}</Link>
                                    ) : (
                                        <Link href={`/catalog/products/${relation.variant.product.id}`} className="font-medium text-ink hover:underline">
                                            {relation.variant.product.name}
                                            <span className="block text-xs text-ink-faint"><bdi>{[relation.variant.label, relation.variant.sku, relation.variant.reference].filter(Boolean).join(' · ')}</bdi></span>
                                        </Link>
                                    )}
                                    <span className="mt-1 flex flex-wrap gap-1">
                                        {relation.is_preferred && <span className="rounded-full bg-success-soft px-2 py-0.5 text-xs text-success">{t('supplierCatalog.preferred')}</span>}
                                        {!relation.supplier.active && <span className="rounded-full bg-raised px-2 py-0.5 text-xs text-ink-muted">{t('supplierCatalog.inactiveSupplier')}</span>}
                                        {relation.priority !== null && <span className="text-xs text-ink-faint">{t('supplierCatalog.priority')} {relation.priority}</span>}
                                    </span>
                                </td>
                                <td className="px-3 py-2"><bdi>{[relation.supplier_sku, relation.supplier_reference].filter(Boolean).join(' / ') || '—'}</bdi></td>
                                <td className="px-3 py-2">
                                    {relation.prices_visible ? (relation.purchase_price !== null ? formatMoney(relation.purchase_price, relation.currency_code) : '—') : t('supplierCatalog.priceHidden')}
                                </td>
                                <td className="px-3 py-2">{relation.minimum_order_quantity !== null ? formatQuantity(relation.minimum_order_quantity) : '—'}</td>
                                <td className="px-3 py-2">{relation.lead_time_days !== null ? `${relation.lead_time_days} ${t('supplierCatalog.days')}` : '—'}</td>
                                <td className="px-3 py-2">
                                    {last ? (
                                        <span>
                                            <Link href={`/procurement/purchase-orders/${last.purchase_order_id}`} className="text-ink hover:underline"><bdi>{last.purchase_order_number}</bdi></Link>
                                            <span className="block text-xs text-ink-faint">
                                                {formatDate(last.order_date)} · {formatQuantity(last.quantity)}
                                                {last.unit_price !== null && ` · ${formatMoney(last.unit_price, last.currency_code)}`}
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="text-ink-faint">{t('supplierCatalog.noPurchase')}</span>
                                    )}
                                </td>
                                <td className="px-3 py-2"><StatusBadge status={relation.status} /></td>
                                {canManage && (
                                    <td className="px-3 py-2 text-end">
                                        <div className="flex flex-wrap justify-end gap-2 text-xs">
                                            <button type="button" onClick={() => onEdit(relation)} className="text-ink underline">{t('supplierCatalog.editRelation')}</button>
                                            {relation.status === 'active' && !relation.is_preferred && relation.supplier.active && (
                                                <button type="button" onClick={() => patch(relation, { is_preferred: true })} className="text-ink underline">{t('supplierCatalog.setPreferred')}</button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => patch(relation, { status: relation.status === 'active' ? 'inactive' : 'active' })}
                                                className="text-ink-muted underline"
                                            >
                                                {relation.status === 'active' ? t('supplierCatalog.deactivate') : t('supplierCatalog.activate')}
                                            </button>
                                        </div>
                                    </td>
                                )}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
