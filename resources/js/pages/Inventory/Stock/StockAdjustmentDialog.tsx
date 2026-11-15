import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { formatQuantity } from '@/utils/format';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

export type AdjustableRow = {
    id: number;
    label: string | null;
    sku: string;
    reference: string | null;
    product: { name: string };
    warehouses: Array<{ id: number; name: string; code: string; on_hand: string; reserved: string; available: string }>;
    initial_warehouse_ids: number[];
};
type Warehouse = { id: number; name: string; code: string };
type Mode = 'set' | 'increase' | 'decrease' | 'initial';
type Preview = { valid: boolean; on_hand_before: string; on_hand_after: string; delta: string; issues: Array<{ code: string; message: string }> };

const REASON_OTHER = '__other';
const REASON_KEYS = ['inventory.adjust.reason.physical', 'inventory.adjust.reason.correction', 'inventory.adjust.reason.loss', 'inventory.adjust.reason.manualReceipt'] as const;

const scaled = (value: string) => Math.round(Number(value.replace(',', '.')) * 10000);
const fromScaled = (value: number) => (value / 10000).toFixed(4);

/** Instant on-screen hint only: the server preview is authoritative at the review step. */
function localPlan(mode: Mode, onHand: string, reserved: string, quantity: string) {
    const raw = quantity.trim().replace(',', '.');
    if (raw === '' || !/^\d+(\.\d{1,4})?$/.test(raw)) return null;
    const current = scaled(onHand);
    const amount = scaled(raw);
    const after = mode === 'set' || mode === 'initial' ? amount : mode === 'increase' ? current + amount : current - amount;
    const delta = after - current;
    const available = current - scaled(reserved);
    const issue =
        after < 0 ? 'negative_result' : delta < 0 && -delta > available ? 'exceeds_available' : (mode === 'increase' || mode === 'decrease' || mode === 'initial') && amount === 0 ? 'invalid_quantity' : delta === 0 ? 'no_change' : null;
    return { after: fromScaled(after), delta: fromScaled(delta), issue };
}

export default function StockAdjustmentDialog({
    row,
    warehouses,
    canInitial,
    defaultWarehouseId,
    onClose,
}: {
    row: AdjustableRow | null;
    warehouses: Warehouse[];
    canInitial: boolean;
    defaultWarehouseId?: string;
    onClose: () => void;
}) {
    // Remount per product so every opening starts clean.
    return row ? <Inner key={row.id} row={row} warehouses={warehouses} canInitial={canInitial} defaultWarehouseId={defaultWarehouseId} onClose={onClose} /> : null;
}

function Inner({ row, warehouses, canInitial, defaultWarehouseId, onClose }: { row: AdjustableRow; warehouses: Warehouse[]; canInitial: boolean; defaultWarehouseId?: string; onClose: () => void }) {
    const { t } = useI18n();
    const initialWarehouse = defaultWarehouseId ?? String(row.warehouses[0]?.id ?? warehouses[0]?.id ?? '');
    const [warehouseId, setWarehouseId] = useState(initialWarehouse);
    const initialEligible = (id: string) => canInitial && row.initial_warehouse_ids.includes(Number(id));
    const [mode, setMode] = useState<Mode>(initialEligible(initialWarehouse) ? 'initial' : 'set');
    const [quantity, setQuantity] = useState('');
    const [reasonChoice, setReasonChoice] = useState('');
    const [reasonText, setReasonText] = useState('');
    const [reference, setReference] = useState('');
    const [step, setStep] = useState<'form' | 'review'>('form');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);

    const stock = row.warehouses.find((warehouse) => String(warehouse.id) === warehouseId) ?? { on_hand: '0', reserved: '0', available: '0' };
    const warehouse = warehouses.find((item) => String(item.id) === warehouseId);
    const reasonOptions = REASON_KEYS.map((key) => t(key));
    const reason = reasonChoice === REASON_OTHER ? reasonText.trim() : reasonChoice;
    const canUseInitial = initialEligible(warehouseId);
    const effectiveMode: Mode = mode === 'initial' && !canUseInitial ? 'set' : mode;
    const plan = useMemo(() => localPlan(effectiveMode, stock.on_hand, stock.reserved, quantity), [effectiveMode, stock.on_hand, stock.reserved, quantity]);
    const needsReason = effectiveMode !== 'initial';
    const issueText = plan?.issue ? t(`inventory.adjust.issue.${plan.issue}` as never) : null;
    const canReview = plan !== null && plan.issue === null && (!needsReason || reason !== '') && warehouseId !== '';

    const modes: Array<{ value: Mode; label: string }> = [
        ...(canUseInitial ? [{ value: 'initial' as Mode, label: t('inventory.adjust.mode.initial') }] : []),
        { value: 'set', label: t('inventory.adjust.mode.set') },
        { value: 'increase', label: t('inventory.adjust.mode.increase') },
        { value: 'decrease', label: t('inventory.adjust.mode.decrease') },
    ];
    const quantityLabel = effectiveMode === 'increase' ? t('inventory.adjust.qty.increase') : effectiveMode === 'decrease' ? t('inventory.adjust.qty.decrease') : t('inventory.adjust.qty.set');
    const variantName = `${row.product.name}${row.label ? ` · ${row.label}` : ''}`;

    const payload = () => ({
        warehouse_id: Number(warehouseId),
        product_variant_id: row.id,
        quantity: quantity.trim().replace(',', '.'),
        reason: reason || undefined,
        reference: reference.trim() || undefined,
    });

    async function review(event: FormEvent) {
        event.preventDefault();
        if (!canReview || !plan) return;
        setErrors([]);
        if (effectiveMode === 'initial') {
            setPreview({ valid: true, on_hand_before: stock.on_hand, on_hand_after: plan.after, delta: plan.delta, issues: [] });
            setStep('review');
            return;
        }
        setBusy(true);
        try {
            const { data } = await axios.post<Preview>('/inventory/adjustments/preview', { ...payload(), operation: effectiveMode });
            setPreview(data);
            if (data.valid) setStep('review');
            else setErrors(data.issues.map((issue) => t(`inventory.adjust.issue.${issue.code}` as never) || issue.message));
        } catch (error) {
            const body = axios.isAxiosError(error) ? error.response?.data : null;
            setErrors(body?.errors ? Object.values<string[]>(body.errors).flat() : [t('inventory.adjust.error')]);
        } finally {
            setBusy(false);
        }
    }

    function confirm() {
        if (!preview) return;
        setBusy(true);
        const common = { preserveScroll: true, onSuccess: onClose, onError: (bag: Record<string, string>) => { setErrors(Object.values(bag)); setStep('form'); }, onFinish: () => setBusy(false) };
        if (effectiveMode === 'initial') router.post('/inventory/opening-stock', payload(), common);
        else router.post('/inventory/adjustments', { ...payload(), operation: effectiveMode, expected_on_hand: preview.on_hand_before }, common);
    }

    const signed = (value: string) => (Number(value) > 0 ? `+${formatQuantity(value)}` : formatQuantity(value));

    return (
        <Dialog
            open
            onClose={onClose}
            size="md"
            title={t('inventory.stock.adjustStock')}
            description={step === 'review' ? t('inventory.adjust.reviewHelp') : undefined}
            dismissible={!busy}
            footer={
                step === 'form' ? (
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" form="stock-adjust-form" disabled={!canReview} loading={busy}>{t('inventory.adjust.review')}</Button>
                    </div>
                ) : (
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setStep('form')} disabled={busy}>{t('common.back')}</Button>
                        <Button onClick={confirm} loading={busy} loadingText={t('inventory.stock.adjusting')}>{t('inventory.adjust.confirm')}</Button>
                    </div>
                )
            }
        >
            <div className="space-y-4 px-4 py-4 sm:px-6">
                <div className="rounded-field bg-raised p-3 text-sm">
                    <p className="font-semibold text-ink">{variantName}</p>
                    <p className="text-xs text-ink-muted"><bdi>{row.sku}</bdi>{row.reference ? <> · <bdi>{row.reference}</bdi></> : null}</p>
                    <dl className="mt-3 grid grid-cols-3 gap-3">
                        <Figure label={t('inventory.stock.onHand')} value={formatQuantity(stock.on_hand)} />
                        <Figure label={t('inventory.stock.reserved')} value={formatQuantity(stock.reserved)} />
                        <Figure label={t('inventory.stock.available')} value={formatQuantity(stock.available)} />
                    </dl>
                </div>

                {errors.length > 0 && (
                    <div role="alert" className="rounded-field border border-danger/25 bg-danger-soft px-3 py-2 text-sm text-danger">
                        {errors.map((message, index) => <p key={index}>{message}</p>)}
                    </div>
                )}

                {step === 'form' ? (
                    <form id="stock-adjust-form" onSubmit={review} className="space-y-4">
                        <SelectField label={t('inventory.stock.warehouse')} value={warehouseId} onChange={(event) => { setWarehouseId(event.target.value); setPreview(null); }} required>
                            {warehouses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </SelectField>

                        <fieldset>
                            <legend className="mb-1.5 text-sm font-medium text-ink">{t('inventory.adjust.operation')}</legend>
                            <div className="grid gap-2 sm:grid-cols-3" role="radiogroup">
                                {modes.map((option) => (
                                    <label key={option.value} className={`flex cursor-pointer items-center justify-center rounded-field border px-3 py-2.5 text-center text-sm font-medium transition-soft focus-within:ring-2 focus-within:ring-ink/30 ${effectiveMode === option.value ? 'border-ink bg-ink text-surface' : 'border-line bg-surface text-ink hover:bg-sage'}`}>
                                        <input type="radio" name="operation" value={option.value} checked={effectiveMode === option.value} onChange={() => setMode(option.value)} className="sr-only" />
                                        {option.label}
                                    </label>
                                ))}
                            </div>
                        </fieldset>

                        <TextField label={quantityLabel} inputMode="decimal" autoFocus required value={quantity} onChange={(event) => setQuantity(event.target.value)} error={issueText ?? undefined} />

                        {plan && !plan.issue && (
                            <p className="rounded-field bg-sage px-3 py-2 text-sm tabular-nums text-ink" aria-live="polite">
                                <bdi>{formatQuantity(stock.on_hand)} → {formatQuantity(plan.after)}</bdi> · {t('inventory.adjust.variation')} : <bdi>{signed(plan.delta)}</bdi>
                            </p>
                        )}

                        {needsReason && (
                            <>
                                <SelectField label={t('inventory.stock.reason')} value={reasonChoice} onChange={(event) => setReasonChoice(event.target.value)} required hint={t('inventory.adjust.reasonHint')}>
                                    <option value="">{t('inventory.adjust.reasonPick')}</option>
                                    {reasonOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                                    <option value={REASON_OTHER}>{t('inventory.adjust.reason.other')}</option>
                                </SelectField>
                                {reasonChoice === REASON_OTHER && <TextField label={t('inventory.adjust.reasonDetail')} required value={reasonText} onChange={(event) => setReasonText(event.target.value)} maxLength={500} />}
                            </>
                        )}
                        <TextField label={t('inventory.stock.referenceOptional')} value={reference} onChange={(event) => setReference(event.target.value)} maxLength={255} />
                    </form>
                ) : (
                    preview && (
                        <dl className="divide-y divide-line rounded-field border border-line text-sm">
                            <Line label={t('inventory.stock.warehouse')} value={warehouse?.name ?? ''} />
                            <Line label={t('inventory.stock.product')} value={variantName} />
                            <Line label={t('inventory.adjust.currentStock')} value={formatQuantity(preview.on_hand_before)} />
                            <Line label={t('inventory.adjust.operation')} value={modes.find((option) => option.value === effectiveMode)?.label ?? ''} />
                            <Line label={t('inventory.adjust.variation')} value={signed(preview.delta)} />
                            <Line label={t('inventory.adjust.resulting')} value={`${formatQuantity(preview.on_hand_before)} → ${formatQuantity(preview.on_hand_after)}`} strong />
                            {reason && <Line label={t('inventory.stock.reason')} value={reason} />}
                            {reference.trim() && <Line label={t('inventory.stock.referenceOptional')} value={reference.trim()} />}
                        </dl>
                    )
                )}
            </div>
        </Dialog>
    );
}

function Figure({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-ink-muted">{label}</dt>
            <dd className="mt-0.5 font-semibold tabular-nums text-ink">{value}</dd>
        </div>
    );
}

function Line({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex justify-between gap-4 px-3 py-2">
            <dt className="text-ink-muted">{label}</dt>
            <dd className={`text-end tabular-nums ${strong ? 'font-semibold text-ink' : 'text-ink'}`}><bdi>{value}</bdi></dd>
        </div>
    );
}
