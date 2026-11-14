import SupplierRelationDialog, { type SupplierRelation } from '@/components/procurement/SupplierRelationDialog';
import SupplierRelationTable from '@/components/procurement/SupplierRelationTable';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { ButtonLink } from '@/components/ui/Button';
import { TablePager } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Supplier = {
    id: number;
    name: string;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    notes: string | null;
    active: boolean;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    supplier: Supplier;
    relations: { data: SupplierRelation[]; links: LinkData[]; from?: number | null; to?: number | null; total: number } | null;
    filters: { search?: string; status?: string };
    can: { manage: boolean; editSupplier: boolean; viewPrices: boolean };
    defaultCurrency: string;
};

export default function SupplierShow({ supplier, relations, filters, can, defaultCurrency }: Props) {
    const { t } = useI18n();
    const [dialog, setDialog] = useState<{ relation: SupplierRelation | null } | null>(null);
    // SupplierController@show validates `search` and `status` (active|inactive).
    const f = useQueryFilters(`/procurement/suppliers/${supplier.id}`, filters, { keys: ['search', 'status'] });
    const statusOptions = [
        { value: 'active', label: t('status.active') },
        { value: 'inactive', label: t('status.inactive') },
    ];
    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) {
        chips.push({
            key: 'status',
            label: t('common.status'),
            value: statusOptions.find((option) => option.value === filters.status)?.label ?? filters.status,
            onRemove: () => f.remove('status'),
        });
    }

    return (
        <ProcurementLayout>
            <Head title={supplier.name} />
            <PageHeader
                title={supplier.name}
                description={[supplier.contact_person, supplier.phone, supplier.email].filter(Boolean).join(' · ') || undefined}
                actions={
                    <ButtonLink href="/procurement/suppliers" variant="secondary">
                        {t('suppliers.backToList')}
                    </ButtonLink>
                }
            />

            <section className="mb-6 grid gap-3 surface-card p-5 text-sm sm:grid-cols-2">
                <div>
                    <span className="mb-1 block text-[13px] text-ink-muted">{t('common.status')}</span>
                    <StatusBadge status={supplier.active ? 'active' : 'inactive'} />
                </div>
                <div>
                    <span className="mb-1 block text-[13px] text-ink-muted">{t('suppliers.address')}</span>
                    <span className="text-ink">{supplier.address ?? '—'}</span>
                </div>
                {supplier.notes && (
                    <div className="sm:col-span-2">
                        <span className="mb-1 block text-[13px] text-ink-muted">{t('suppliers.notes')}</span>
                        <span className="text-ink">{supplier.notes}</span>
                    </div>
                )}
            </section>

            {relations !== null && (
                <section>
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">{t('supplierCatalog.supplierSection')}</h2>
                            <p className="text-sm text-ink-muted">{t('supplierCatalog.supplierSectionHint')}</p>
                        </div>
                        {can.manage && supplier.active && <Button onClick={() => setDialog({ relation: null })}>{t('supplierCatalog.addProduct')}</Button>}
                    </div>
                    <div className="mb-4">
                        <AdvancedFilterBar
                            label={t('supplierCatalog.supplierSection')}
                            search={
                                <FilterSearch
                                    value={f.search}
                                    onChange={f.setSearch}
                                    loading={f.searching}
                                    label={t('common.search')}
                                    placeholder={t('supplierCatalog.searchPlaceholder')}
                                />
                            }
                            chips={chips}
                            activeCount={f.activeCount}
                            loading={f.loading && !f.searching}
                            onReset={f.reset}
                        >
                            <ChoiceFilter
                                label={t('common.status')}
                                allLabel={t('supplierCatalog.allStatuses')}
                                options={statusOptions}
                                value={filters.status}
                                onChange={(value) => f.set('status', value)}
                            />
                        </AdvancedFilterBar>
                    </div>
                    {relations.data.length === 0 ? (
                        <EmptyState
                            title={f.activeCount > 0 ? t('filters.noMatch') : t('supplierCatalog.noProducts')}
                            description={f.activeCount > 0 ? t('filters.noMatchHelp') : t('supplierCatalog.supplierSectionHint')}
                            actions={
                                f.activeCount > 0 ? (
                                    <Button variant="secondary" onClick={f.reset}>
                                        {t('filters.reset')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <div className={f.loading ? 'opacity-55 transition-opacity' : 'transition-opacity'} aria-busy={f.loading || undefined}>
                            <SupplierRelationTable relations={relations.data} side="supplier" canManage={can.manage} onEdit={(relation) => setDialog({ relation })} />
                        </div>
                    )}
                    <div className="mt-3">
                        <TablePager links={relations.links} from={relations.from} to={relations.to} total={relations.total} />
                    </div>
                </section>
            )}

            {dialog && (
                <SupplierRelationDialog
                    open
                    onClose={() => setDialog(null)}
                    fixed={{ kind: 'supplier', id: supplier.id, label: supplier.name }}
                    relation={dialog.relation}
                    defaultCurrency={defaultCurrency}
                    viewPrices={can.viewPrices}
                    currentPreferred={dialog.relation?.variant_preferred ?? null}
                />
            )}
        </ProcurementLayout>
    );
}
