import TransferLinePicker, { type PickerItem, type PickerLine } from '@/components/inventory/TransferLinePicker';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import SandboxStockBadge from '@/components/SandboxStockBadge';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import { newOperationId } from '@/utils/uuid';
import InventoryLayout from '@/layouts/InventoryLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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

const fieldClass = 'control-input h-11';
const selectClass = 'control-select h-11';
const labelClass = 'block text-[13px] font-medium text-ink-muted';

const toItem = (result: SearchResult): PickerItem => ({
    product_variant_id: result.id,
    product_name: result.product.name,
    label: result.label,
    sku: result.sku,
    reference: result.reference,
    brand: result.product.brand?.name ?? null,
    stock: result.availability,
});

export default function TransferCreate({ filters, warehouses, searchResults, preselectedVariant }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [lines, setLines] = useState<PickerLine[]>([]);
    // One idempotency key per form instance: a retry / double click replays the same transfer.
    // It only changes when the user explicitly starts a NEW transfer, never on a retry.
    const [operationId, setOperationId] = useState(newOperationId);
    const [submitted, setSubmitted] = useState(false);
    // The request ended without a server answer (network drop, 5xx, non-Inertia reply): the
    // transfer may or may not exist. The same operation id is replayed on retry.
    const [uncertain, setUncertain] = useState(false);
    // The lookup by operation id proved no transfer exists for it (yet): retrying is safe.
    const [notCreated, setNotCreated] = useState(false);
    const [checking, setChecking] = useState(false);
    const [created, setCreated] = useState<{ url: string; number: string } | null>(null);
    const form = useForm({
        source_warehouse_id: filters.source_warehouse_id ? String(filters.source_warehouse_id) : '',
        destination_warehouse_id: filters.destination_warehouse_id ? String(filters.destination_warehouse_id) : '',
        reason: '',
        lines: [] as Array<{ product_variant_id: number; quantity: number }>,
    });

    const sameRoute = Boolean(form.data.source_warehouse_id && form.data.source_warehouse_id === form.data.destination_warehouse_id);
    const sourceSelected = form.data.source_warehouse_id !== '';
    const canSubmit = !form.processing && !submitted && !sameRoute && lines.length > 0 && Boolean(form.data.source_warehouse_id && form.data.destination_warehouse_id && form.data.reason.trim());
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!preselectedVariant) return;
        setLines((current) => current.some((line) => line.product_variant_id === preselectedVariant.id) ? current : [
            ...current,
            {
                key: String(preselectedVariant.id),
                product_variant_id: preselectedVariant.id,
                product_name: preselectedVariant.product.name,
                label: preselectedVariant.label,
                sku: preselectedVariant.sku,
                reference: preselectedVariant.reference,
                brand: preselectedVariant.product.brand?.name ?? null,
                stock: null,
                quantity: '1',
            },
        ]);
    }, [preselectedVariant?.id]);

    useEffect(() => {
        // Never fire a partial reload while a submit is in flight / being resolved: Inertia would
        // cancel the POST client-side even though the server still commits it.
        if (!sourceSelected || form.processing || submitted || checking) return;
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
    }, [search, form.data.source_warehouse_id, form.data.destination_warehouse_id, form.processing, submitted, checking]);

    // Keep the stock shown next to each selected line in sync with the latest search.
    useEffect(() => {
        setLines((current) => current.map((line) => {
            const result = searchResults.find((item) => item.id === line.product_variant_id);
            return result ? { ...line, stock: result.availability } : line;
        }));
    }, [searchResults]);

    /**
     * The submit ended without a clean Inertia success/validation answer (cancelled visit, dropped
     * connection, unfollowed redirect...). Do not guess: ask the server whether this exact
     * operation id created a transfer. Read-only, so it can never duplicate anything.
     */
    async function resolveOutcome(operation: string) {
        setChecking(true);
        try {
            const response = await fetch(`/inventory/transfers/operations/${operation}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(`lookup ${response.status}`);
            const body = await response.json() as { found?: boolean; url?: string; transfer_number?: string };
            if (body.found === true && typeof body.url === 'string') {
                setCreated({ url: body.url, number: String(body.transfer_number ?? '') });
                setSubmitted(true);
                setLines([]);
                form.reset();
            } else if (body.found === false) {
                setNotCreated(true);
            } else {
                setUncertain(true);
            }
        } catch {
            // The lookup itself failed: this is the only genuinely ambiguous case.
            setUncertain(true);
        } finally {
            setChecking(false);
        }
    }

    function submit() {
        if (submitted || form.processing || checking) return;
        form.transform((data) => ({
            ...data,
            lines: lines.map((line) => ({ product_variant_id: line.product_variant_id, quantity: Number(line.quantity) })),
            client_operation_id: operationId,
        }));
        setUncertain(false);
        setNotCreated(false);
        const operation = operationId;
        // A success or a validation error is a definite answer; anything else is resolved by lookup.
        let answered = false;
        form.post('/inventory/transfers', {
            preserveScroll: true,
            onSuccess: () => {
                answered = true;
                // The server normally redirects to the created transfer. If this component is
                // still mounted, lock the form and show an explicit confirmation instead.
                setSubmitted(true);
                setLines([]);
                form.reset();
            },
            onError: () => {
                answered = true;
            },
            onFinish: () => {
                if (!answered) void resolveOutcome(operation);
            },
        });
    }

    function startNewTransfer() {
        setOperationId(newOperationId());
        setSubmitted(false);
        setUncertain(false);
        setNotCreated(false);
        setCreated(null);
        setLines([]);
        form.reset();
        form.clearErrors();
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
                <SandboxStockBadge />
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

            {submitted && (
                <Alert tone="success" className="mb-4 px-4 py-3" title={t('inventory.transferCreate.successTitle')}>
                    <p>{t('inventory.transferCreate.successBody')}</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        <ButtonLink href={created?.url ?? '/inventory/transfers'} variant="secondary">{created?.number ? `${t('inventory.transferCreate.viewTransfer')} ${created.number}` : t('inventory.transferCreate.viewTransfers')}</ButtonLink>
                        <Button type="button" variant="secondary" onClick={startNewTransfer}>{t('inventory.transferCreate.newTransfer')}</Button>
                    </div>
                </Alert>
            )}
            {checking && (
                <Alert tone="info" className="mb-4 px-4 py-3">{t('inventory.transferCreate.checking')}</Alert>
            )}
            {notCreated && !submitted && !checking && (
                <Alert tone="danger" className="mb-4 px-4 py-3" title={t('inventory.transferCreate.notCreatedTitle')}>
                    {t('inventory.transferCreate.notCreatedBody')}
                </Alert>
            )}
            {Object.keys(form.errors).length > 0 && !submitted && (
                <Alert tone="danger" className="mb-4 px-4 py-3" title={t('inventory.transferCreate.failedTitle')}>
                    {Object.values(form.errors as Record<string, string>)[0]}
                </Alert>
            )}
            {uncertain && !submitted && !checking && (
                <Alert tone="warning" className="mb-4 px-4 py-3" title={t('inventory.transferCreate.uncertainTitle')}>
                    {t('inventory.transferCreate.uncertainBody')}
                </Alert>
            )}

            <section className="mb-4 surface-card p-4 shadow-soft sm:p-5">
                <h2 className="text-base font-semibold text-ink">{t('inventory.transferCreate.info')}</h2>
                <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1.4fr]">
                    <Field label={t('inventory.transferCreate.source')} error={form.errors.source_warehouse_id}>
                        <select value={form.data.source_warehouse_id} onChange={(event) => form.setData('source_warehouse_id', event.target.value)} className={selectClass}>
                            <option value="">{t('inventory.transferCreate.selectSource')}</option>
                            {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} · {warehouse.code}</option>)}
                        </select>
                    </Field>
                    <Field label={t('inventory.transferCreate.destination')} error={form.errors.destination_warehouse_id ?? (sameRoute ? t('inventory.transferCreate.sameRoute') : undefined)}>
                        <select value={form.data.destination_warehouse_id} onChange={(event) => form.setData('destination_warehouse_id', event.target.value)} className={selectClass}>
                            <option value="">{t('inventory.transferCreate.selectDestination')}</option>
                            {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id} disabled={String(warehouse.id) === form.data.source_warehouse_id}>{warehouse.name} · {warehouse.code}</option>)}
                        </select>
                    </Field>
                    <Field label={t('inventory.transferCreate.reason')} error={form.errors.reason} className="md:col-span-2 xl:col-span-1">
                        <input
                            required
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            className={fieldClass}
                            placeholder={t('inventory.transferCreate.reasonPlaceholder')}
                        />
                    </Field>
                </div>
            </section>

            <h2 className="mb-1 text-base font-semibold text-ink">{t('inventory.transferCreate.products')}</h2>
            <p className="mb-3 text-sm text-ink-muted">{t('inventory.transferCreate.productsHelp')}</p>

            <TransferLinePicker
                sourceSelected={sourceSelected}
                search={search}
                onSearch={setSearch}
                searching={searching}
                results={searchResults.map(toItem)}
                lines={lines.map((line, index) => ({ ...line, error: errors[`lines.${index}.quantity`] ?? errors[`lines.${index}.product_variant_id`] }))}
                onAdd={(item) => setLines((current) => current.some((l) => l.product_variant_id === item.product_variant_id) ? current : [...current, { ...item, key: String(item.product_variant_id), quantity: '1' }])}
                onQuantity={(key, value) => setLines((current) => current.map((l) => (l.key === key ? { ...l, quantity: value } : l)))}
                onRemove={(key) => setLines((current) => current.filter((l) => l.key !== key))}
                listError={form.errors.lines}
                footer={
                    <div className="space-y-3">
                        {sameRoute && <p className="rounded-field border border-warning/30 bg-warning-soft px-3 py-2 text-xs text-warning">{t('inventory.transferCreate.destinationDifferent')}</p>}
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Link href="/inventory/transfers" className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-sage">{t('common.cancel')}</Link>
                            <Button type="button" onClick={submit} disabled={!canSubmit || checking} loading={form.processing || checking} loadingText={t('inventory.transferCreate.creating')}>
                                {uncertain || notCreated ? t('inventory.transferCreate.retry') : t('inventory.transferCreate.create')}
                            </Button>
                        </div>
                        <p className="text-xs leading-relaxed text-ink-muted">{t('inventory.transferCreate.serverValidation')}</p>
                    </div>
                }
            />
        </InventoryLayout>
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
