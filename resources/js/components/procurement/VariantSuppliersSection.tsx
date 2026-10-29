import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import { useState } from 'react';
import SupplierRelationDialog, { type PreferredSupplier, type SupplierRelation } from './SupplierRelationDialog';
import SupplierRelationTable from './SupplierRelationTable';

type Variant = { id: number; label: string | null; sku: string | null };

/** "Fournisseurs" on the product page: each variant with its supplier catalog relations. */
export default function VariantSuppliersSection({
    productName,
    variants,
    relationsByVariant,
    canManage,
    viewPrices,
    defaultCurrency,
}: {
    productName: string;
    variants: Variant[];
    relationsByVariant: Record<string, SupplierRelation[]>;
    canManage: boolean;
    viewPrices: boolean;
    defaultCurrency: string;
}) {
    const { t } = useI18n();
    const [dialog, setDialog] = useState<{ variant: Variant; relation: SupplierRelation | null } | null>(null);
    const name = (variant: Variant) => [productName, variant.label, variant.sku].filter(Boolean).join(' — ');
    const preferredOf = (variantId: number): PreferredSupplier | null => {
        const preferred = (relationsByVariant[variantId] ?? []).find((relation) => relation.is_preferred);
        return preferred ? { relation_id: preferred.id, supplier_id: preferred.supplier.id, supplier_name: preferred.supplier.name } : null;
    };

    return (
        <section className="rounded-card border border-line bg-surface p-5 sm:p-6">
            <h2 className="text-base font-semibold text-ink">{t('supplierCatalog.productSection')}</h2>
            <p className="mt-1 max-w-3xl text-sm text-ink-muted">{t('supplierCatalog.productSectionHint')}</p>
            <div className="mt-4 space-y-5">
                {variants.map((variant) => {
                    const relations = relationsByVariant[variant.id] ?? [];
                    return (
                        <div key={variant.id}>
                            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <p className="min-w-0 truncate text-sm font-medium text-ink"><bdi>{[variant.label, variant.sku].filter(Boolean).join(' · ') || productName}</bdi></p>
                                {canManage && relations.length > 0 && (
                                    <Button size="sm" variant="secondary" type="button" onClick={() => setDialog({ variant, relation: null })}>
                                        {t('supplierCatalog.addSupplier')}
                                    </Button>
                                )}
                            </div>
                            {relations.length === 0 ? (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-dashed border-line-strong bg-raised px-4 py-4">
                                    <p className="text-sm text-ink-muted">{t('supplierCatalog.noSuppliers')}</p>
                                    {canManage && (
                                        <Button size="sm" type="button" onClick={() => setDialog({ variant, relation: null })}>
                                            {t('supplierCatalog.addSupplier')}
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <SupplierRelationTable relations={relations} side="variant" canManage={canManage} onEdit={(relation) => setDialog({ variant, relation })} />
                            )}
                        </div>
                    );
                })}
            </div>
            {dialog && (
                <SupplierRelationDialog
                    open
                    onClose={() => setDialog(null)}
                    fixed={{ kind: 'variant', id: dialog.variant.id, label: name(dialog.variant) }}
                    relation={dialog.relation}
                    defaultCurrency={defaultCurrency}
                    viewPrices={viewPrices}
                    currentPreferred={preferredOf(dialog.variant.id)}
                />
            )}
        </section>
    );
}
