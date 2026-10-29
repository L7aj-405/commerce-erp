import SupplierRelationDialog, { type SupplierRelation } from '@/components/procurement/SupplierRelationDialog';
import SupplierRelationTable from '@/components/procurement/SupplierRelationTable';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
    relations: { data: SupplierRelation[]; links: LinkData[]; total: number } | null;
    filters: { search?: string; status?: string };
    can: { manage: boolean; editSupplier: boolean; viewPrices: boolean };
    defaultCurrency: string;
};

export default function SupplierShow({ supplier, relations, filters, can, defaultCurrency }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [dialog, setDialog] = useState<{ relation: SupplierRelation | null } | null>(null);

    const visit = (next: Record<string, string | undefined>) =>
        router.get(`/procurement/suppliers/${supplier.id}`, next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search: search || undefined, status: filters.status }), 280);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <ProcurementLayout>
            <Head title={supplier.name} />
            <PageHeader
                title={supplier.name}
                description={[supplier.contact_person, supplier.phone, supplier.email].filter(Boolean).join(' · ') || undefined}
                actions={
                    <Link href="/procurement/suppliers" className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised">
                        {t('suppliers.backToList')}
                    </Link>
                }
            />

            <section className="mb-6 grid gap-3 rounded-card border border-line bg-surface p-5 text-sm sm:grid-cols-2">
                <div><span className="block text-xs uppercase tracking-wide text-ink-faint">{t('common.status')}</span><StatusBadge status={supplier.active ? 'active' : 'inactive'} /></div>
                <div><span className="block text-xs uppercase tracking-wide text-ink-faint">{t('suppliers.address')}</span>{supplier.address ?? '—'}</div>
                {supplier.notes && <div className="sm:col-span-2"><span className="block text-xs uppercase tracking-wide text-ink-faint">{t('suppliers.notes')}</span>{supplier.notes}</div>}
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
                    <div className="mb-4 flex flex-wrap gap-3">
                        <div className="min-w-[240px] flex-1">
                            <SearchInput value={search} onChange={setSearch} searching={searching} placeholder={t('supplierCatalog.searchPlaceholder')} />
                        </div>
                        <select
                            value={filters.status ?? ''}
                            onChange={(event) => visit({ search: search || undefined, status: event.target.value || undefined })}
                            className="control-input"
                        >
                            <option value="">{t('supplierCatalog.allStatuses')}</option>
                            <option value="active">{t('status.active')}</option>
                            <option value="inactive">{t('status.inactive')}</option>
                        </select>
                    </div>
                    {relations.data.length === 0 ? (
                        <EmptyState title={t('supplierCatalog.noProducts')} description={t('supplierCatalog.supplierSectionHint')} />
                    ) : (
                        <SupplierRelationTable relations={relations.data} side="supplier" canManage={can.manage} onEdit={(relation) => setDialog({ relation })} />
                    )}
                    <Pagination links={relations.links} />
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
