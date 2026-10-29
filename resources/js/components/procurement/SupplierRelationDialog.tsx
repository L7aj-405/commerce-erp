import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';

/** A variant's current preferred supplier (SupplierCatalogQuery::preferredByVariant). */
export type PreferredSupplier = { relation_id: number; supplier_id: number; supplier_name: string | null };

/** One supplier catalog relation as returned by SupplierCatalogQuery. */
export type SupplierRelation = {
    id: number;
    supplier: { id: number; name: string | null; active: boolean };
    variant: { id: number; sku: string | null; label: string | null; reference: string | null; status: string | null; product: { id: number; name: string | null } };
    supplier_sku: string | null;
    supplier_reference: string | null;
    supplier_product_name: string | null;
    purchase_price: string | null;
    prices_visible: boolean;
    currency_code: string;
    minimum_order_quantity: string | null;
    order_multiple: string | null;
    lead_time_days: number | null;
    is_preferred: boolean;
    priority: number | null;
    status: string;
    quality_rating: number | null;
    reliability_rating: number | null;
    notes: string | null;
    last_purchase: {
        purchase_order_id: number;
        purchase_order_number: string;
        order_date: string;
        unit_price: string | null;
        quantity: string;
        currency_code: string;
        total_quantity: string;
        order_count: number;
    } | null;
    /** Supplier page only: the variant's current preferred supplier, possibly another one. */
    variant_preferred?: PreferredSupplier | null;
};

type Option = { id: number; label: string; preferred: PreferredSupplier | null };

type Props = {
    open: boolean;
    onClose: () => void;
    /** Creating from a product page fixes the variant; from a supplier page, the supplier. */
    fixed: { kind: 'variant'; id: number; label: string } | { kind: 'supplier'; id: number; label: string };
    relation: SupplierRelation | null;
    defaultCurrency: string;
    viewPrices: boolean;
    /** The fixed variant's current preferred supplier (product page, or the edited relation's variant). */
    currentPreferred: PreferredSupplier | null;
};

const fieldsOf = (relation: SupplierRelation | null, currency: string) => ({
    supplier_sku: relation?.supplier_sku ?? '',
    supplier_reference: relation?.supplier_reference ?? '',
    supplier_product_name: relation?.supplier_product_name ?? '',
    purchase_price: relation?.purchase_price ?? '',
    currency_code: relation?.currency_code ?? currency,
    minimum_order_quantity: relation?.minimum_order_quantity ?? '',
    order_multiple: relation?.order_multiple ?? '',
    lead_time_days: relation?.lead_time_days?.toString() ?? '',
    priority: relation?.priority?.toString() ?? '',
    quality_rating: relation?.quality_rating?.toString() ?? '',
    reliability_rating: relation?.reliability_rating?.toString() ?? '',
    notes: relation?.notes ?? '',
    is_preferred: relation?.is_preferred ?? false,
});

export default function SupplierRelationDialog({ open, onClose, fixed, relation, defaultCurrency, viewPrices, currentPreferred }: Props) {
    const { t } = useI18n();
    const form = useForm<Record<string, string | boolean | number | null>>({ ...fieldsOf(relation, defaultCurrency), counterpart_id: '' });
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<Option[]>([]);
    const editing = relation !== null;
    const pickKind = fixed.kind === 'variant' ? 'supplier' : 'variant';

    useEffect(() => {
        if (!open) return;
        form.setData({ ...fieldsOf(relation, defaultCurrency), counterpart_id: '' });
        form.clearErrors();
        setSearch('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, relation?.id]);

    useEffect(() => {
        if (!open || editing) return;
        const timer = window.setTimeout(() => {
            const url = pickKind === 'supplier' ? '/procurement/supplier-catalog/supplier-options' : '/procurement/supplier-catalog/variant-options';
            axios.get(url, { params: { search: search || undefined } }).then(({ data }) => {
                setOptions(
                    (data.data as Array<Record<string, unknown>>).map((row) => ({
                        id: Number(row.id),
                        label: pickKind === 'supplier' ? String(row.name) : [row.product_name, row.label, row.sku].filter(Boolean).join(' — '),
                        preferred: (row.preferred as PreferredSupplier | null | undefined) ?? null,
                    })),
                );
            });
        }, 250);
        return () => window.clearTimeout(timer);
    }, [open, editing, search, pickKind]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => {
            const payload: Record<string, unknown> = {};
            for (const [key, value] of Object.entries(data)) {
                if (key === 'counterpart_id') continue;
                if (key === 'purchase_price' && !viewPrices) continue;
                payload[key] = value === '' ? null : value;
            }
            if (!editing) {
                payload.supplier_id = fixed.kind === 'supplier' ? fixed.id : Number(data.counterpart_id);
                payload.product_variant_id = fixed.kind === 'variant' ? fixed.id : Number(data.counterpart_id);
            }
            return payload;
        });
        const options = { preserveScroll: true, onSuccess: onClose };
        if (editing) form.patch(`/procurement/supplier-catalog/${relation.id}`, options);
        else form.post('/procurement/supplier-catalog', options);
    };

    const text = (key: string, label: TranslationKey, props: Record<string, unknown> = {}) => (
        <Field label={t(label)} error={form.errors[key]}>
            <input value={String(form.data[key] ?? '')} onChange={(event) => form.setData(key, event.target.value)} className="control-input w-full" {...props} />
        </Field>
    );
    // Creating from the supplier page: the picked variant decides who is preferred today.
    const preferredNow = !editing && pickKind === 'variant'
        ? options.find((option) => option.id === Number(form.data.counterpart_id))?.preferred ?? null
        : currentPreferred;
    const replaces = Boolean(form.data.is_preferred) && preferredNow !== null && preferredNow.relation_id !== relation?.id
        && !(fixed.kind === 'supplier' && preferredNow.supplier_id === fixed.id);
    const decimal = { inputMode: 'decimal', pattern: '^\\d+(?:\\.\\d{1,4})?$' };
    const integer = { inputMode: 'numeric', pattern: '^\\d+$' };

    return (
        <Dialog
            open={open}
            onClose={() => !form.processing && onClose()}
            title={editing ? t('supplierCatalog.editRelation') : fixed.kind === 'variant' ? t('supplierCatalog.addSupplier') : t('supplierCatalog.addProduct')}
            description={editing ? `${relation.supplier.name ?? ''} · ${relation.variant.product.name ?? ''} ${relation.variant.sku ?? ''}` : fixed.label}
            dismissible={!form.processing}
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" type="button" onClick={onClose} disabled={form.processing}>{t('common.cancel')}</Button>
                    <Button type="submit" form="supplier-relation-form" loading={form.processing} disabled={!editing && !form.data.counterpart_id}>{t('common.save')}</Button>
                </div>
            }
        >
            <form id="supplier-relation-form" onSubmit={submit} className="space-y-5 px-4 py-5 sm:px-6">
                {!editing && (
                    <div className="space-y-1.5">
                        <Field label={pickKind === 'supplier' ? t('supplierCatalog.supplier') : t('supplierCatalog.product')} error={form.errors.supplier_id ?? form.errors.product_variant_id}>
                            <input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder={pickKind === 'supplier' ? t('supplierCatalog.searchSupplier') : t('supplierCatalog.searchVariant')}
                                className="control-input mb-2 w-full"
                            />
                            <select
                                value={String(form.data.counterpart_id ?? '')}
                                onChange={(event) => form.setData('counterpart_id', event.target.value)}
                                className="control-input w-full"
                                required
                            >
                                <option value="">{t('supplierCatalog.choose')}</option>
                                {options.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}
                            </select>
                        </Field>
                        <p className="text-xs text-ink-faint">{t('supplierCatalog.reactivateHint')}</p>
                    </div>
                )}
                <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                    {text('supplier_sku', 'supplierCatalog.supplierSkuField')}
                    {text('supplier_reference', 'supplierCatalog.supplierReference')}
                    {text('supplier_product_name', 'supplierCatalog.supplierProductName')}
                    {viewPrices && text('purchase_price', 'supplierCatalog.purchasePrice', decimal)}
                    {text('currency_code', 'supplierCatalog.currency', { maxLength: 3 })}
                    {text('minimum_order_quantity', 'supplierCatalog.moq', decimal)}
                    {text('order_multiple', 'supplierCatalog.orderMultiple', decimal)}
                    {text('lead_time_days', 'supplierCatalog.leadTimeDays', integer)}
                    {text('priority', 'supplierCatalog.priority', integer)}
                    {text('quality_rating', 'supplierCatalog.qualityRating', integer)}
                    {text('reliability_rating', 'supplierCatalog.reliabilityRating', integer)}
                </div>
                <Field label={t('supplierCatalog.notes')} error={form.errors.notes}>
                    <textarea value={String(form.data.notes ?? '')} onChange={(event) => form.setData('notes', event.target.value)} rows={2} className="control-input w-full" />
                </Field>
                <label className="flex cursor-pointer items-start gap-3 rounded-field border border-line bg-raised px-3 py-3 text-sm text-ink">
                    <input type="checkbox" checked={Boolean(form.data.is_preferred)} onChange={(event) => form.setData('is_preferred', event.target.checked)} className="mt-0.5 size-4 shrink-0" />
                    <span className="min-w-0">
                        {t('supplierCatalog.markPreferred')}
                        {replaces && preferredNow && (
                            <small role="status" className="block text-warning">
                                {t('supplierCatalog.preferredReplacesNamed').replace('{name}', preferredNow.supplier_name ?? '—')}
                            </small>
                        )}
                    </span>
                </label>
                {(form.errors.is_preferred || form.errors.status || form.errors.fields) && (
                    <p className="text-xs text-danger">{form.errors.is_preferred ?? form.errors.status ?? form.errors.fields}</p>
                )}
            </form>
        </Dialog>
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
