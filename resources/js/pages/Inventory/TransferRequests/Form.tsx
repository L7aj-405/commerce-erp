import TransferLinePicker, { type PickerItem, type PickerLine } from '@/components/inventory/TransferLinePicker';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import InventoryLayout from '@/layouts/InventoryLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

type Warehouse = { id: number; name: string; code: string };
type ExistingLine = PickerItem & { id: number; reason: string; protected: boolean; quantity: string };
type EditRequest = {
    id: number;
    request_number: string;
    lock_version: number;
    note: string | null;
    source: Warehouse | null;
    destination: Warehouse | null;
    sales_order: { id: number; order_number: string } | null;
    lines: ExistingLine[];
};
type Prefill = { request_number: string; source_warehouse_id: number; destination_warehouse_id: number; lines: Array<{ product_variant_id: number; quantity: string }> } | null;
type Props = {
    mode: 'create' | 'edit';
    request: EditRequest | null;
    prefill: Prefill;
    filters: { source_warehouse_id?: number; destination_warehouse_id?: number; search?: string; selected?: string; from?: number };
    warehouses: Warehouse[];
    searchResults: PickerItem[];
    selectedStock: Record<string, PickerItem>;
    operationId: string | null;
};
type FormLine = PickerLine & { lineId: number | null; reason: string };

const fieldClass = 'h-11 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';
const labelClass = 'block text-xs font-semibold uppercase tracking-wide text-ink-muted';
const trim = (q: string) => String(Number(q));

export default function TransferRequestForm({ mode, request, prefill, filters, warehouses, searchResults, selectedStock, operationId }: Props) {
    const { t } = useI18n();
    const editing = mode === 'edit' && request !== null;
    const reasonLabel: Record<string, string> = {
        order_fulfillment: t('inventory.requests.orderReason'),
        minimum_replenishment: t('inventory.requests.replenishmentReason'),
        manual: t('inventory.requests.manualReason'),
    };

    const initialLines = (): FormLine[] => {
        if (editing) {
            return request.lines.map((l) => ({
                ...l,
                key: `l-${l.id}`,
                lineId: l.id,
                quantity: trim(l.quantity),
                badge: reasonLabel[l.reason] ?? l.reason,
                hint: l.protected ? t('inventory.requestForm.protectedHint') : null,
            }));
        }
        if (prefill) {
            return prefill.lines
                .filter((l) => selectedStock[String(l.product_variant_id)])
                .map((l) => ({
                    ...selectedStock[String(l.product_variant_id)],
                    key: `v-${l.product_variant_id}`,
                    lineId: null,
                    reason: 'manual',
                    quantity: trim(l.quantity),
                    badge: reasonLabel.manual,
                }));
        }
        return [];
    };

    const [lines, setLines] = useState<FormLine[]>(initialLines);
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const form = useForm({
        source_warehouse_id: String(editing ? request.source?.id ?? '' : filters.source_warehouse_id ?? ''),
        destination_warehouse_id: String(editing ? request.destination?.id ?? '' : filters.destination_warehouse_id ?? ''),
        note: editing ? request.note ?? '' : '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const sourceSelected = form.data.source_warehouse_id !== '';
    const sameRoute = sourceSelected && form.data.source_warehouse_id === form.data.destination_warehouse_id;
    const editable = lines.filter((l) => !l.protected);
    const canSubmit = !form.processing && !sameRoute && Boolean(form.data.source_warehouse_id && form.data.destination_warehouse_id)
        && lines.length > 0 && editable.every((l) => Number(l.quantity) > 0);

    // Partial reload: catalogue + fresh stock for the selected lines.
    const selectedIds = useMemo(() => lines.map((l) => l.product_variant_id).join(','), [lines]);
    const firstRun = useRef(true);
    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;
            if (!search && searchResults.length > 0) return;
        }
        if (!sourceSelected) return;
        const timer = window.setTimeout(() => {
            router.get(editing ? `/inventory/transfer-requests/${request.id}/edit` : '/inventory/transfer-requests/create', {
                source_warehouse_id: editing ? undefined : form.data.source_warehouse_id || undefined,
                destination_warehouse_id: editing ? undefined : form.data.destination_warehouse_id || undefined,
                search: search || undefined,
                selected: selectedIds || undefined,
            }, {
                only: ['searchResults', 'selectedStock', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            });
        }, 280);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, form.data.source_warehouse_id, form.data.destination_warehouse_id]);

    useEffect(() => {
        setLines((current) => current.map((line) => {
            const fresh = selectedStock[String(line.product_variant_id)] ?? searchResults.find((r) => r.product_variant_id === line.product_variant_id);
            return fresh?.stock ? { ...line, stock: fresh.stock } : line;
        }));
    }, [selectedStock, searchResults]);

    const addItem = (item: PickerItem) => setLines((current) => current.some((l) => !l.protected && l.product_variant_id === item.product_variant_id)
        ? current
        : [...current, { ...item, key: `v-${item.product_variant_id}`, lineId: null, reason: 'manual', quantity: '1', badge: reasonLabel.manual }]);

    function submit() {
        const payloadLines = editable.map((l) => ({ id: l.lineId, product_variant_id: l.product_variant_id, quantity: l.quantity }));
        if (editing) {
            form.transform(() => ({ lock_version: request.lock_version, note: form.data.note, lines: payloadLines }));
            form.put(`/inventory/transfer-requests/${request.id}/lines`, { preserveScroll: true });
        } else {
            form.transform((data) => ({
                source_warehouse_id: Number(data.source_warehouse_id),
                destination_warehouse_id: Number(data.destination_warehouse_id),
                note: data.note,
                operation_id: operationId,
                lines: payloadLines.map(({ product_variant_id, quantity }) => ({ product_variant_id, quantity })),
            }));
            form.post('/inventory/transfer-requests', { preserveScroll: true });
        }
    }

    // Server errors are keyed by the index of the submitted (editable) lines.
    const linesWithErrors: FormLine[] = lines.map((line) => {
        const index = editable.indexOf(line);
        return index < 0 ? line : { ...line, error: errors[`lines.${index}.quantity`] ?? errors[`lines.${index}.product_variant_id`] ?? errors[`lines.${index}.id`] };
    });

    const title = editing ? t('inventory.requestForm.editTitle').replace('{number}', request.request_number) : t('inventory.requestForm.createTitle');

    if (!editing && warehouses.length < 2) {
        return (
            <InventoryLayout>
                <Head title={title} />
                <PageHeader title={title} actions={<ButtonLink href="/inventory/transfer-requests" variant="secondary">{t('inventory.requests.back')}</ButtonLink>} />
                <EmptyState title={t('inventory.transferCreate.twoRequired')} description={t('inventory.transferCreate.twoRequiredHelp')} />
            </InventoryLayout>
        );
    }

    return (
        <InventoryLayout wide>
            <Head title={title} />
            <PageHeader
                title={title}
                description={editing ? t('inventory.requestForm.editHelp') : t('inventory.requestForm.createHelp')}
                actions={<ButtonLink href={editing ? `/inventory/transfer-requests/${request.id}` : '/inventory/transfer-requests'} variant="secondary">{t('inventory.requests.back')}</ButtonLink>}
            />

            {errors.lock_version && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning" role="alert">
                    <span>{errors.lock_version}</span>
                    <button type="button" onClick={() => router.reload()} className="font-semibold underline">{t('inventory.requestForm.reload')}</button>
                </div>
            )}
            {prefill && (
                <p className="mb-4 rounded-card border border-line bg-raised px-4 py-3 text-sm text-ink-muted">
                    {t('inventory.requestForm.prefilled').replace('{number}', prefill.request_number)}
                </p>
            )}

            <section className="mb-4 rounded-card border border-line bg-surface p-4 shadow-soft sm:p-5">
                <h2 className="text-base font-semibold text-ink">{t('inventory.transferCreate.info')}</h2>
                {editing ? (
                    <dl className="mt-3 grid gap-3 text-sm md:grid-cols-[1fr_1fr_1.4fr]">
                        <div><dt className={labelClass}>{t('inventory.transferCreate.source')}</dt><dd className="mt-1.5 font-medium text-ink">{request.source?.name ?? '—'} · {request.source?.code}</dd></div>
                        <div><dt className={labelClass}>{t('inventory.transferCreate.destination')}</dt><dd className="mt-1.5 font-medium text-ink">{request.destination?.name ?? '—'} · {request.destination?.code}</dd></div>
                        <label className="block">
                            <span className={labelClass}>{t('inventory.requestForm.note')}</span>
                            <input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className={`${fieldClass} mt-1.5`} placeholder={t('inventory.requests.optional')} />
                        </label>
                    </dl>
                ) : (
                    <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1.4fr]">
                        <label className="block">
                            <span className={labelClass}>{t('inventory.transferCreate.source')}</span>
                            <select value={form.data.source_warehouse_id} onChange={(e) => form.setData('source_warehouse_id', e.target.value)} className={`${fieldClass} mt-1.5`}>
                                <option value="">{t('inventory.transferCreate.selectSource')}</option>
                                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name} · {w.code}</option>)}
                            </select>
                            {errors.source_warehouse_id && <p className="mt-1 text-xs text-danger">{errors.source_warehouse_id}</p>}
                        </label>
                        <label className="block">
                            <span className={labelClass}>{t('inventory.transferCreate.destination')}</span>
                            <select value={form.data.destination_warehouse_id} onChange={(e) => form.setData('destination_warehouse_id', e.target.value)} className={`${fieldClass} mt-1.5`}>
                                <option value="">{t('inventory.transferCreate.selectDestination')}</option>
                                {warehouses.map((w) => <option key={w.id} value={w.id} disabled={String(w.id) === form.data.source_warehouse_id}>{w.name} · {w.code}</option>)}
                            </select>
                            {(errors.destination_warehouse_id || sameRoute) && <p className="mt-1 text-xs text-danger">{errors.destination_warehouse_id ?? t('inventory.transferCreate.sameRoute')}</p>}
                        </label>
                        <label className="block md:col-span-2 xl:col-span-1">
                            <span className={labelClass}>{t('inventory.requestForm.note')}</span>
                            <input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className={`${fieldClass} mt-1.5`} placeholder={t('inventory.requestForm.notePlaceholder')} />
                        </label>
                    </div>
                )}
                {editing && request.sales_order && (
                    <p className="mt-3 text-xs text-ink-muted">
                        {t('inventory.requestForm.orderLinked').replace('{order}', request.sales_order.order_number)}
                    </p>
                )}
            </section>

            <TransferLinePicker
                sourceSelected={sourceSelected}
                search={search}
                onSearch={setSearch}
                searching={searching}
                results={searchResults}
                lines={linesWithErrors}
                onAdd={addItem}
                onQuantity={(key, value) => setLines((current) => current.map((l) => (l.key === key ? { ...l, quantity: value } : l)))}
                onRemove={(key) => setLines((current) => current.filter((l) => l.key !== key))}
                limitField="transferable"
                step="any"
                listError={errors.lines ?? errors.status}
                selectedTitle={t('inventory.requestForm.selected')}
                footer={
                    <div className="space-y-3">
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Link href={editing ? `/inventory/transfer-requests/${request.id}` : '/inventory/transfer-requests'} className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-sage">
                                {t('common.cancel')}
                            </Link>
                            <Button type="button" onClick={submit} disabled={!canSubmit} loading={form.processing} loadingText={t('inventory.requestForm.saving')}>
                                {editing ? t('inventory.requestForm.save') : t('inventory.requestForm.create')}
                            </Button>
                        </div>
                        <p className="text-xs leading-relaxed text-ink-muted">{t('inventory.requestForm.serverValidation')}</p>
                    </div>
                }
            />
        </InventoryLayout>
    );
}
