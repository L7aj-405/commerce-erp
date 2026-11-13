import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import SalesLayout from '@/layouts/SalesLayout';
import { useI18n } from '@/i18n';
import { formatMoney, formatQuantity } from '@/utils/format';
import { Head, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';

type Item = {
    id: number;
    name: string;
    reference: string | null;
    unit_label: string | null;
    price_input_mode: 'ht' | 'ttc';
    default_price_excl_tax: string | null;
    default_price_incl_tax: string | null;
    tax_rate: string;
    tax_name: string | null;
    usage_count: number;
    status: string;
    created_at: string;
    created_by: { name: string } | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    items: { data: Item[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number };
    filters: { search?: string; status?: string };
    taxRates: { id: number; name: string; rate: string }[];
    can: { manage: boolean };
};

export default function NonStockItemsIndex({ items, filters, taxRates, can }: Props) {
    const { t } = useI18n();
    const [editing, setEditing] = useState<number | null>(null);
    const f = useQueryFilters('/catalog/non-stock-items', filters, { keys: ['search', 'status'] });
    const statusLabel = (status: string) => (status === 'active' ? t('catalog.nonStock.active') : t('catalog.nonStock.inactive'));
    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: statusLabel(filters.status), onRemove: () => f.remove('status') });
    const filtered = f.activeCount > 0;

    return (
        <SalesLayout>
            <Head title={t('catalog.nonStock.title')} />
            <PageHeader
                title={t('catalog.nonStock.title')}
                description={t('catalog.nonStock.description')}
                actions={
                    <a
                        href="/catalog/non-stock-items/export"
                        className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised"
                    >
                        {t('catalog.nonStock.export')}
                    </a>
                }
            />

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('catalog.nonStock.search')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter
                        label={t('common.status')}
                        allLabel={t('catalog.nonStock.all')}
                        options={['active', 'inactive'].map((value) => ({ value, label: statusLabel(value) }))}
                        value={filters.status}
                        onChange={(value) => f.set('status', value)}
                    />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('catalog.nonStock.title')}
                state={items.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={filtered ? t('filters.noMatch') : t('catalog.nonStock.empty')}
                emptyDescription={filtered ? t('filters.noMatchHelp') : undefined}
                emptyAction={
                    filtered ? (
                        <Button variant="secondary" onClick={f.reset}>
                            {t('filters.reset')}
                        </Button>
                    ) : undefined
                }
                footer={items.data.length === 0 ? undefined : <TablePager links={items.links} from={items.from} to={items.to} total={items.total} />}
            >
                <table className="erp-table min-w-[860px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('catalog.nonStock.designation')}</th>
                            <th className="px-4 py-2.5">{t('catalog.nonStock.reference')}</th>
                            <th className="num px-4 py-2.5">{t('catalog.nonStock.priceExcl')}</th>
                            <th className="num px-4 py-2.5">{t('catalog.nonStock.priceIncl')}</th>
                            <th className="px-4 py-2.5">TVA</th>
                            <th className="num px-4 py-2.5">{t('catalog.nonStock.quotations')}</th>
                            <th className="px-4 py-2.5">{t('common.status')}</th>
                            {can.manage && <th className="px-4 py-2.5"><span className="sr-only">{t('common.actions')}</span></th>}
                        </tr>
                    </thead>
                    <tbody>
                        {items.data.map((item) => (
                            <Fragment key={item.id}>
                                <tr>
                                    <td className="px-4 py-3 font-medium text-ink">{item.name}</td>
                                    <td className="px-4 py-3 text-ink-muted"><bdi>{item.reference ?? '—'}</bdi></td>
                                    <td className="num px-4 py-3">{item.default_price_excl_tax ? formatMoney(item.default_price_excl_tax) : '—'}</td>
                                    <td className="num px-4 py-3">{item.default_price_incl_tax ? formatMoney(item.default_price_incl_tax) : '—'}</td>
                                    <td className="px-4 py-3 text-ink-muted">{formatQuantity(item.tax_rate)} %</td>
                                    <td className="num px-4 py-3">{item.usage_count}</td>
                                    <td className="px-4 py-3">
                                        <DocBadge tone={item.status === 'active' ? 'positive' : 'neutral'}>{item.status === 'active' ? t('catalog.nonStock.active') : t('catalog.nonStock.inactive')}</DocBadge>
                                    </td>
                                    {can.manage && (
                                        <td className="px-4 py-3 text-end">
                                            <Button size="sm" variant="outline" aria-expanded={editing === item.id} onClick={() => setEditing(editing === item.id ? null : item.id)}>
                                                {t('common.edit')}
                                                <span className="sr-only"> {item.name}</span>
                                            </Button>
                                        </td>
                                    )}
                                </tr>
                                {editing === item.id && can.manage && (
                                    <tr className="bg-raised/40">
                                        <td colSpan={8} className="px-4 py-3">
                                            <EditRow item={item} taxRates={taxRates} onDone={() => setEditing(null)} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </TableShell>
            <p className="mt-3 text-[13px] text-ink-muted">
                {t('catalog.nonStock.futureHelp')}
            </p>
        </SalesLayout>
    );
}

function EditRow({ item, taxRates, onDone }: { item: Item; taxRates: { id: number; name: string; rate: string }[]; onDone: () => void }) {
    const { t } = useI18n();
    const form = useForm({
        name: item.name,
        reference: item.reference ?? '',
        unit_label: item.unit_label ?? '',
        price_input_mode: item.price_input_mode,
        unit_price: item.price_input_mode === 'ttc' ? (item.default_price_incl_tax ?? '') : (item.default_price_excl_tax ?? ''),
        tax_rate_id: '' as string,
        status: item.status,
    });

    const submit = () => form.patch(`/catalog/non-stock-items/${item.id}`, { preserveScroll: true, onSuccess: onDone });

    return (
        <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={t('catalog.nonStock.designation')} className={input} />
            <input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} placeholder={t('catalog.nonStock.reference')} className={input} />
            <input value={form.data.unit_label} onChange={(e) => form.setData('unit_label', e.target.value)} placeholder={t('catalog.nonStock.unit')} className={input} />
            <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className="control-select w-full">
                <option value="active">{t('catalog.nonStock.active')}</option>
                <option value="inactive">{t('catalog.nonStock.inactive')}</option>
            </select>
            <div className="flex gap-1">
                <input value={form.data.unit_price} onChange={(e) => form.setData('unit_price', e.target.value)} placeholder={t('catalog.nonStock.price')} className={`${input} text-end`} />
                <select value={form.data.price_input_mode} onChange={(e) => form.setData('price_input_mode', e.target.value as 'ht' | 'ttc')} aria-label="HT / TTC" className="control-select w-24 shrink-0">
                    <option value="ht">HT</option>
                    <option value="ttc">TTC</option>
                </select>
            </div>
            <select value={form.data.tax_rate_id} onChange={(e) => form.setData('tax_rate_id', e.target.value)} className="control-select w-full">
                <option value="">{t('catalog.nonStock.taxUnchanged')}</option>
                <option value="0">{t('catalog.nonStock.noTax')}</option>
                {taxRates.map((t) => (
                    <option key={t.id} value={t.id}>
                        {t.name} ({formatQuantity(t.rate)} %)
                    </option>
                ))}
            </select>
            <div className="col-span-2 flex gap-2 md:col-span-4">
                <Button size="sm" onClick={submit} loading={form.processing}>
                    {t('common.save')}
                </Button>
                <Button size="sm" variant="secondary" onClick={onDone}>
                    {t('common.cancel')}
                </Button>
            </div>
        </div>
    );
}

const input = 'control-input w-full min-w-0';
