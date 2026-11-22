import { MatchingStatusBadge, SupplierInvoiceStatusBadge } from '@/components/finance/supplier-invoices';
import type { SupplierInvoiceIndexProps } from '@/components/finance/supplier-invoices';
import { TestModeRestrictionNotice } from '@/components/finance/supplier-invoices/TestModeRestrictionNotice';
import { interpolate } from '@/components/finance/supplier-invoices/math';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link, router, usePage } from '@inertiajs/react';

const BASE = '/finance/factures-fournisseurs';

/** Read-only list. Create/approve/void wiring arrives with the write UI (PR12). */
export default function SupplierInvoicesIndex(props: Partial<SupplierInvoiceIndexProps>) {
    const { t } = useI18n();
    const page = usePage<{ sandbox?: { active: boolean } }>();
    const sandbox = Boolean(page.props.sandbox?.active);
    const { invoices, filters, stores = [], suppliers = [] } = props;
    const f = useQueryFilters(BASE, { search: filters?.search ?? '', status: filters?.status ?? '', supplier_id: String(filters?.supplier_id ?? ''), store_id: String(filters?.store_id ?? '') }, { keys: ['search', 'status', 'supplier_id', 'store_id'] });

    const statusOptions = (['draft', 'approved', 'void'] as const).map((value) => ({ value, label: t(`supplierInvoice.status.${value}`) }));
    const supplierOptions = suppliers.map((s) => ({ value: String(s.id), label: s.name }));
    const storeOptions = stores.map((s) => ({ value: String(s.id), label: s.name }));
    const chips: FilterChip[] = [];
    if (filters?.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    const chip = (key: 'status' | 'supplier_id' | 'store_id', label: string, options: { value: string; label: string }[]) => {
        const current = filters?.[key];
        if (current !== null && current !== undefined && current !== '') chips.push({ key, label, value: options.find((o) => o.value === String(current))?.label ?? String(current), onRemove: () => f.remove(key) });
    };
    chip('status', t('supplierInvoice.col.status'), statusOptions);
    chip('supplier_id', t('supplierInvoice.col.supplier'), supplierOptions);
    chip('store_id', t('supplierInvoice.col.store'), storeOptions);

    const header = <PageHeader title={t('supplierInvoice.title')} description={t('supplierInvoice.description')} />;

    if (sandbox) {
        return (
            <ApplicationShell wide>
                <Head title={t('supplierInvoice.title')} />
                {header}
                <TestModeRestrictionNotice />
            </ApplicationShell>
        );
    }

    // `invoices` missing means the server sent nothing usable: show an explicit error with retry, never a blank page.
    if (!invoices) {
        return (
            <ApplicationShell wide>
                <Head title={t('supplierInvoice.title')} />
                {header}
                <EmptyState title={t('supplierInvoice.loadError')} actions={<Button variant="secondary" onClick={() => router.reload()}>{t('ui.retry')}</Button>} />
            </ApplicationShell>
        );
    }

    const rows = invoices.data;
    const filtered = f.activeCount > 0;
    const emptyTitle = filtered ? t('filters.noMatch') : t('supplierInvoice.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('supplierInvoice.emptyHelp');
    const emptyAction = filtered ? <Button variant="secondary" onClick={f.reset}>{t('filters.reset')}</Button> : undefined;
    const pager = <TablePager links={invoices.links} from={undefined} to={undefined} total={invoices.total} />;

    return (
        <ApplicationShell wide>
            <Head title={t('supplierInvoice.title')} />
            {header}
            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('supplierInvoice.title')}
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('supplierInvoice.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('supplierInvoice.col.status')} allLabel={t('supplierInvoice.allStatuses')} options={statusOptions} value={filters?.status ?? undefined} onChange={(v) => f.set('status', v)} />
                    <ChoiceFilter label={t('supplierInvoice.col.supplier')} allLabel={t('supplierInvoice.allSuppliers')} options={supplierOptions} searchable value={filters?.supplier_id ? String(filters.supplier_id) : undefined} onChange={(v) => f.set('supplier_id', v)} />
                    <ChoiceFilter label={t('supplierInvoice.col.store')} allLabel={t('supplierInvoice.allStores')} options={storeOptions} value={filters?.store_id ? String(filters.store_id) : undefined} onChange={(v) => f.set('store_id', v)} />
                </AdvancedFilterBar>
            </div>

            <TableShell label={t('supplierInvoice.title')} state={rows.length === 0 ? 'empty' : 'ready'} busy={f.loading} emptyTitle={emptyTitle} emptyDescription={emptyDescription} emptyAction={emptyAction} footer={rows.length === 0 ? undefined : pager}>
                <table className="erp-table min-w-[860px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t('supplierInvoice.col.internalNumber')}</th>
                            <th className="px-4 py-3">{t('supplierInvoice.col.invoiceNumber')}</th>
                            <th className="px-4 py-3">{t('supplierInvoice.col.date')}</th>
                            <th className="px-4 py-3">{t('supplierInvoice.col.supplier')}</th>
                            <th className="px-4 py-3">{t('supplierInvoice.col.store')}</th>
                            <th className="px-4 py-3 text-end">{t('supplierInvoice.col.total')}</th>
                            <th className="px-4 py-3">{t('supplierInvoice.col.status')}</th>
                            <th className="px-4 py-3">{t('supplierInvoice.col.match')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 font-semibold">
                                    <Link href={`${BASE}/${row.id}`} className="text-ink hover:text-accent hover:underline">
                                        <bdi>{row.internal_number}</bdi>
                                    </Link>
                                </td>
                                <td className="px-4 py-3">
                                    <bdi>{row.invoice_number}</bdi>
                                    {row.has_attachments && <span className="ms-1.5 text-ink-faint" title={t('supplierInvoice.hasAttachments')} aria-label={t('supplierInvoice.hasAttachments')}>📎</span>}
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{formatDate(row.invoice_date)}</td>
                                <td className="px-4 py-3">{row.supplier.name}</td>
                                <td className="px-4 py-3 text-ink-muted">{row.store.name}</td>
                                <td className="px-4 py-3 text-end font-medium tabular-nums">{formatMoney(row.total_ttc, row.currency_code)}</td>
                                <td className="px-4 py-3">
                                    <SupplierInvoiceStatusBadge status={row.status} />
                                    {row.warnings_count > 0 && <p className="mt-1 text-[12px] text-warning">{interpolate(t('supplierInvoice.warningsCount'), { count: row.warnings_count })}</p>}
                                </td>
                                <td className="px-4 py-3">
                                    <MatchingStatusBadge status={row.match_status} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>
        </ApplicationShell>
    );
}
