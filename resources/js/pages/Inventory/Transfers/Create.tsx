import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import SearchInput from '@/components/ui/SearchInput';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatInteger, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

type Warehouse = { id: number; name: string; code: string };
type SearchResult = {
    id: number;
    label: string | null;
    sku: string;
    reference: string | null;
    barcode: string | null;
    product: { id: number; name: string; brand: { id: number; name: string } | null; category: { id: number; name: string } | null };
    availability: { on_hand: string; reserved: string; available: string };
};
type PreselectedVariant = { id: number; label: string | null; sku: string; reference: string | null; product: { id: number; name: string; brand: { id: number; name: string } | null } } | null;
type Props = {
    filters: { source_warehouse_id?: number; destination_warehouse_id?: number; search?: string; variant_id?: number };
    warehouses: Warehouse[];
    searchResults: SearchResult[];
    preselectedVariant: PreselectedVariant;
};
type SelectedLine = {
    product_variant_id: number;
    product_name: string;
    label: string | null;
    sku: string;
    reference: string | null;
    brand: string | null;
    available: string;
    quantity: string;
};

const fieldClass = 'h-11 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary disabled:bg-raised disabled:text-ink-faint';
const labelClass = 'block text-xs font-semibold uppercase tracking-wide text-ink-muted';

export default function TransferCreate({ filters, warehouses, searchResults, preselectedVariant }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [selectedLines, setSelectedLines] = useState<SelectedLine[]>([]);
    const form = useForm({
        source_warehouse_id: filters.source_warehouse_id ? String(filters.source_warehouse_id) : '',
        destination_warehouse_id: filters.destination_warehouse_id ? String(filters.destination_warehouse_id) : '',
        reason: '',
        lines: [] as Array<{ product_variant_id: number; quantity: number }>,
    });

    const source = warehouses.find((warehouse) => warehouse.id === Number(form.data.source_warehouse_id));
    const destination = warehouses.find((warehouse) => warehouse.id === Number(form.data.destination_warehouse_id));
    const sameRoute = Boolean(form.data.source_warehouse_id && form.data.source_warehouse_id === form.data.destination_warehouse_id);
    const sourceSelected = form.data.source_warehouse_id !== '';
    const totalUnits = selectedLines.reduce((carry, line) => carry + (Number(line.quantity) || 0), 0);
    const canSubmit = !form.processing && !sameRoute && selectedLines.length > 0 && Boolean(form.data.source_warehouse_id && form.data.destination_warehouse_id && form.data.reason.trim());
    const lineErrors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!preselectedVariant) return;
        setSelectedLines((current) => current.some((line) => line.product_variant_id === preselectedVariant.id) ? current : [
            ...current,
            {
                product_variant_id: preselectedVariant.id,
                product_name: preselectedVariant.product.name,
                label: preselectedVariant.label,
                sku: preselectedVariant.sku,
                reference: preselectedVariant.reference,
                brand: preselectedVariant.product.brand?.name ?? null,
                available: '0.0000',
                quantity: '1',
            },
        ]);
    }, [preselectedVariant?.id]);

    useEffect(() => {
        if (!sourceSelected) return;
        const timer = window.setTimeout(() => {
            router.get('/inventory/transfers/create', {
                source_warehouse_id: form.data.source_warehouse_id || undefined,
                destination_warehouse_id: form.data.destination_warehouse_id || undefined,
                search: search || undefined,
                variant_id: filters.variant_id,
            }, {
                only: ['searchResults', 'filters', 'preselectedVariant'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            });
        }, 280);

        return () => window.clearTimeout(timer);
    }, [search, form.data.source_warehouse_id, form.data.destination_warehouse_id]);

    useEffect(() => {
        setSelectedLines((current) => current.map((line) => {
            const result = searchResults.find((item) => item.id === line.product_variant_id);

            return result ? { ...line, available: result.availability.available } : line;
        }));
    }, [searchResults]);

    const selectedIds = useMemo(() => new Set(selectedLines.map((line) => line.product_variant_id)), [selectedLines]);

    function addResult(result: SearchResult) {
        setSelectedLines((current) => current.some((line) => line.product_variant_id === result.id) ? current : [
            ...current,
            {
                product_variant_id: result.id,
                product_name: result.product.name,
                label: result.label,
                sku: result.sku,
                reference: result.reference,
                brand: result.product.brand?.name ?? null,
                available: result.availability.available,
                quantity: '1',
            },
        ]);
    }

    function removeLine(productVariantId: number) {
        setSelectedLines((current) => current.filter((line) => line.product_variant_id !== productVariantId));
    }

    function setQuantity(productVariantId: number, quantity: string) {
        setSelectedLines((current) => current.map((line) => line.product_variant_id === productVariantId ? { ...line, quantity } : line));
    }

    function submit() {
        form.transform((data) => ({
                ...data,
                lines: selectedLines.map((line) => ({
                    product_variant_id: line.product_variant_id,
                    quantity: Number(line.quantity),
                })),
            }));
        form.post('/inventory/transfers', { preserveScroll: true });
    }

    if (warehouses.length < 2) {
        return (
            <InventoryLayout>
                <Head title={t('inventory.transfers.new')} />
                <PageHeader
                    title={t('inventory.transfers.new')}
                    description={t('inventory.transfers.description')}
                    actions={<ButtonLink href="/inventory/transfers" variant="secondary">{t('inventory.transferCreate.back')}</ButtonLink>}
                />
                <EmptyState
                    title={t('inventory.transferCreate.twoRequired')}
                    description={t('inventory.transferCreate.twoRequiredHelp')}
                    actions={<ButtonLink href="/inventory/warehouses">{t('inventory.transferCreate.addLocation')}</ButtonLink>}
                />
            </InventoryLayout>
        );
    }

    return (
        <InventoryLayout wide>
            <Head title={t('inventory.transfers.new')} />
            <PageHeader
                title={t('inventory.transfers.new')}
                description={t('inventory.transferCreate.description')}
                actions={<ButtonLink href="/inventory/transfers" variant="secondary">{t('inventory.transferCreate.back')}</ButtonLink>}
            />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div className="min-w-0 space-y-6">
                    <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
                        <SectionTitle icon={<IconRoute />} title={t('inventory.transferCreate.info')} description={t('inventory.transferCreate.infoHelp')} />
                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                            <Field label={t('inventory.transferCreate.source')} error={form.errors.source_warehouse_id}>
                                <select value={form.data.source_warehouse_id} onChange={(event) => form.setData('source_warehouse_id', event.target.value)} className={fieldClass}>
                                    <option value="">{t('inventory.transferCreate.selectSource')}</option>
                                    {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} · {warehouse.code}</option>)}
                                </select>
                            </Field>
                            <Field label={t('inventory.transferCreate.destination')} error={form.errors.destination_warehouse_id ?? (sameRoute ? t('inventory.transferCreate.sameRoute') : undefined)}>
                                <select value={form.data.destination_warehouse_id} onChange={(event) => form.setData('destination_warehouse_id', event.target.value)} className={fieldClass}>
                                    <option value="">{t('inventory.transferCreate.selectDestination')}</option>
                                    {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id} disabled={String(warehouse.id) === form.data.source_warehouse_id}>{warehouse.name} · {warehouse.code}</option>)}
                                </select>
                            </Field>
                            <Field label={t('inventory.transferCreate.reason')} error={form.errors.reason} className="md:col-span-2">
                                <textarea
                                    required
                                    value={form.data.reason}
                                    onChange={(event) => form.setData('reason', event.target.value)}
                                    rows={3}
                                    className={`${fieldClass} h-auto py-3`}
                                    placeholder={t('inventory.transferCreate.reasonPlaceholder')}
                                />
                            </Field>
                        </div>
                    </section>

                    <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
                        <SectionTitle icon={<IconSearch />} title={t('inventory.transferCreate.products')} description={t('inventory.transferCreate.productsHelp')} />
                        <div className="mt-5 rounded-field border border-line bg-raised p-3">
                            <SearchInput
                                value={search}
                                onChange={setSearch}
                                searching={searching}
                                placeholder={t('inventory.transferCreate.search')}
                            />
                        </div>

                        {!sourceSelected ? (
                            <InlineEmpty title={t('inventory.transferCreate.selectSourceFirst')} description={t('inventory.transferCreate.sourceAvailabilityHelp')} />
                        ) : searchResults.length === 0 ? (
                            <InlineEmpty title={search ? t('inventory.transferCreate.noResult') : t('inventory.transferCreate.noStock')} description={search ? t('inventory.transferCreate.tryAnother') : t('inventory.transferCreate.noTransferable')} />
                        ) : (
                            <div className="mt-4 grid gap-3 md:grid-cols-2">
                                {searchResults.map((result) => {
                                    const alreadySelected = selectedIds.has(result.id);

                                    return (
                                        <button
                                            key={result.id}
                                            type="button"
                                            onClick={() => addResult(result)}
                                            disabled={alreadySelected}
                                            className="rounded-card border border-line bg-surface p-4 text-start transition-soft hover:border-primary/40 hover:bg-sage disabled:cursor-not-allowed disabled:opacity-55"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold text-ink">{result.product.name}</p>
                                                    <p className="mt-0.5 truncate text-xs text-ink-muted">{result.label ?? t('products.mainVariant')} · <bdi>{result.sku}</bdi></p>
                                                    <p className="mt-1 truncate text-xs text-ink-faint">{[result.reference, result.product.brand?.name].filter(Boolean).join(' · ') || t('products.noReference')}</p>
                                                </div>
                                                <div className="shrink-0 text-end">
                                                    <p className="text-[11px] uppercase tracking-wide text-ink-faint">{t('inventory.transferCreate.available')}</p>
                                                    <p className="font-semibold tabular-nums text-ink">{formatQuantity(result.availability.available)}</p>
                                                </div>
                                            </div>
                                            <div className="mt-3 flex items-center justify-between gap-3 text-xs">
                                                <span className="text-ink-muted">{t('inventory.transferCreate.sourceStock').replace('{stock}', formatQuantity(result.availability.on_hand)).replace('{reserved}', formatQuantity(result.availability.reserved))}</span>
                                                <span className="font-medium text-primary">{alreadySelected ? t('inventory.transferCreate.alreadyAdded') : t('pos.add')}</span>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        <div className="mt-6">
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <h3 className="text-sm font-semibold text-ink">{t('inventory.transferCreate.lines')}</h3>
                                {form.errors.lines && <p className="text-sm text-danger">{form.errors.lines}</p>}
                            </div>

                            {selectedLines.length === 0 ? (
                                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center">
                                    <div className="mx-auto grid size-10 place-items-center rounded-full bg-surface text-ink-muted"><IconPackage /></div>
                                    <p className="mt-3 text-sm font-medium text-ink">{t('inventory.transferCreate.noProduct')}</p>
                                    <p className="mt-1 text-sm text-ink-muted">{t('inventory.transferCreate.noProductHelp')}</p>
                                </div>
                            ) : (
                                <>
                                    <div className="hidden overflow-hidden rounded-card border border-line md:block">
                                        <table className="w-full min-w-[820px] text-start text-sm">
                                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                                <tr>
                                                    <th className="px-4 py-3">{t('pos.product')}</th>
                                                    <th className="px-4 py-3">{t('products.variant')}</th>
                                                    <th className="px-4 py-3 text-end">{t('inventory.transferCreate.availableStock')}</th>
                                                    <th className="px-4 py-3 text-end">{t('inventory.transferCreate.quantity')}</th>
                                                    <th className="px-4 py-3 text-end">{t('backups.action')}</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-line">
                                                {selectedLines.map((line, index) => (
                                                    <tr key={line.product_variant_id} className="align-top">
                                                        <td className="px-4 py-3">
                                                            <p className="font-semibold text-ink">{line.product_name}</p>
                                                            <p className="text-xs text-ink-muted">{line.brand ?? t('products.noBrand')} · {line.reference ?? t('products.noReference')}</p>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <span className="inline-flex rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">{line.label ?? t('products.mainVariant')}</span>
                                                            <p className="mt-1 text-xs text-ink-faint">{line.sku}</p>
                                                        </td>
                                                        <td className="px-4 py-3 text-end font-semibold tabular-nums text-ink">{formatQuantity(line.available)}</td>
                                                        <td className="px-4 py-3 text-end">
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={Math.max(1, Number(line.available) || 1)}
                                                                step={1}
                                                                value={line.quantity}
                                                                onChange={(event) => setQuantity(line.product_variant_id, event.target.value)}
                                                                className="ms-auto h-10 w-28 rounded-field border border-line-strong bg-surface px-3 text-end text-sm outline-none transition-soft focus:border-primary"
                                                            />
                                                            {lineErrors[`lines.${index}.quantity`] && <p className="mt-1 text-xs text-danger">{lineErrors[`lines.${index}.quantity`]}</p>}
                                                        </td>
                                                        <td className="px-4 py-3 text-end">
                                                            <button type="button" onClick={() => removeLine(line.product_variant_id)} className="rounded-field px-3 py-2 text-sm font-medium text-danger transition-soft hover:bg-danger-soft">{t('docProfile.remove')}</button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="space-y-3 md:hidden">
                                        {selectedLines.map((line, index) => (
                                            <article key={line.product_variant_id} className="rounded-card border border-line bg-surface p-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-semibold text-ink">{line.product_name}</p>
                                                        <p className="truncate text-xs text-ink-muted">{line.label ?? t('products.mainVariant')} · <bdi>{line.sku}</bdi></p>
                                                    </div>
                                                    <button type="button" onClick={() => removeLine(line.product_variant_id)} className="shrink-0 text-xs font-medium text-danger">{t('docProfile.remove')}</button>
                                                </div>
                                                <dl className="mt-3 grid grid-cols-2 gap-3 rounded-field bg-raised p-3 text-sm">
                                                    <div>
                                                        <dt className="text-xs text-ink-faint">{t('inventory.transferCreate.available')}</dt>
                                                        <dd className="mt-1 font-semibold tabular-nums text-ink">{formatQuantity(line.available)}</dd>
                                                    </div>
                                                    <label>
                                                        <span className="text-xs text-ink-faint">{t('inventory.transferCreate.quantity')}</span>
                                                        <input
                                                            type="number"
                                                            min={1}
                                                            max={Math.max(1, Number(line.available) || 1)}
                                                            step={1}
                                                            value={line.quantity}
                                                            onChange={(event) => setQuantity(line.product_variant_id, event.target.value)}
                                                            className="mt-1 h-9 w-full rounded-field border border-line-strong bg-surface px-2 text-end text-sm"
                                                        />
                                                    </label>
                                                </dl>
                                                {lineErrors[`lines.${index}.quantity`] && <p className="mt-2 text-xs text-danger">{lineErrors[`lines.${index}.quantity`]}</p>}
                                            </article>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                    </section>
                </div>

                <aside className="space-y-4 xl:sticky xl:top-24 xl:self-start">
                    <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
                        <SectionTitle icon={<IconClipboard />} title={t('inventory.transferCreate.summary')} description={t('inventory.transferCreate.summaryHelp')} compact />
                        <div className="mt-5 space-y-3 text-sm">
                            <SummaryRow label={t('common.source')} value={source ? `${source.name} · ${source.code}` : t('inventory.transferCreate.notSelected')} />
                            <SummaryRow label={t('inventory.transferCreate.destination')} value={destination ? `${destination.name} · ${destination.code}` : t('inventory.transferCreate.notSelected')} />
                            <SummaryRow label={t('inventory.transferCreate.productLines')} value={formatInteger(selectedLines.length)} />
                            <SummaryRow label={t('inventory.transferCreate.totalQuantity')} value={formatQuantity(totalUnits)} strong />
                        </div>
                        {sameRoute && <p className="mt-4 rounded-field border border-warning/30 bg-warning-soft px-3 py-2 text-xs text-warning">{t('inventory.transferCreate.destinationDifferent')}</p>}
                        {selectedLines.length === 0 && <p className="mt-4 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">{t('inventory.transferCreate.addLineHelp')}</p>}
                    </section>

                    <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
                        <div className="flex flex-col-reverse gap-2 sm:flex-row xl:flex-col-reverse">
                            <Link href="/inventory/transfers" className="inline-flex min-h-11 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-sage">{t('common.cancel')}</Link>
                            <Button type="button" onClick={submit} disabled={!canSubmit} loading={form.processing} loadingText={t('inventory.transferCreate.creating')}>
                                {t('inventory.transferCreate.create')}
                            </Button>
                        </div>
                        <p className="mt-3 text-xs leading-relaxed text-ink-muted">{t('inventory.transferCreate.serverValidation')}</p>
                    </section>
                </aside>
            </div>
        </InventoryLayout>
    );
}

function SectionTitle({ icon, title, description, compact = false }: { icon: React.ReactNode; title: string; description: string; compact?: boolean }) {
    return (
        <div className="flex items-start gap-3">
            <span className="grid size-10 shrink-0 place-items-center rounded-field bg-sage text-primary">{icon}</span>
            <div>
                <h2 className={`${compact ? 'text-base' : 'text-lg'} font-semibold text-ink`}>{title}</h2>
                <p className="mt-1 text-sm text-ink-muted">{description}</p>
            </div>
        </div>
    );
}

function Field({ label, error, children, className = '' }: { label: string; error?: string; children: React.ReactNode; className?: string }) {
    return (
        <label className={`block ${className}`}>
            <span className={labelClass}>{label}</span>
            <div className="mt-1.5">{children}</div>
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </label>
    );
}

function InlineEmpty({ title, description }: { title: string; description: string }) {
    return (
        <div className="mt-4 rounded-card border border-dashed border-line-strong bg-raised px-4 py-6 text-center">
            <p className="text-sm font-medium text-ink">{title}</p>
            <p className="mt-1 text-sm text-ink-muted">{description}</p>
        </div>
    );
}

function SummaryRow({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-line pb-3 last:border-0 last:pb-0">
            <span className="text-ink-muted">{label}</span>
            <span className={`max-w-[60%] text-end ${strong ? 'font-semibold tabular-nums text-ink' : 'font-medium text-ink'}`}>{value}</span>
        </div>
    );
}

const iconProps = { width: 18, height: 18, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
function IconRoute() { return <svg {...iconProps}><circle cx="6" cy="6" r="2" /><circle cx="18" cy="18" r="2" /><path d="M8 6h4a4 4 0 0 1 0 8h-1a4 4 0 0 0 0 8h7" /></svg>; }
function IconSearch() { return <svg {...iconProps}><circle cx="11" cy="11" r="7" /><path d="m16 16 4 4" /></svg>; }
function IconPackage() { return <svg {...iconProps}><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M4 7l8 4 8-4M12 11v10" /></svg>; }
function IconClipboard() { return <svg {...iconProps}><path d="M8 4h8l1 3H7l1-3Z" /><path d="M7 6H5v15h14V6h-2M8 12h8M8 16h6" /></svg>; }
