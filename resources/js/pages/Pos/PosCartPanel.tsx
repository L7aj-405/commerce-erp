import ProductImage from '@/components/catalog/ProductImage';
import { Money, NumericInput, Quantity, SegmentedControl } from '@/components/pos/primitives';
import { Button, IconButton } from '@/components/ui/Button';
import { InlineLoader, Spinner } from '@/components/ui/Spinner';
import { formatEditableNumber } from '@/utils/format';
import { useI18n } from '@/i18n';
import { useEffect, useRef, useState } from 'react';
import type { PosPending } from './pos-shared';
import PosCustomerCard from './PosCustomerCard';
import type { ActiveSale, CartLine, Customer, StockConflict } from './types';

type Props = {
    sale: ActiveSale | null;
    currencyCode: string;
    pending: PosPending;
    canApplyDiscount: boolean;
    heldCount: number;
    /** Set when the last finalization was rejected because stock changed. */
    stockConflict: StockConflict | null;
    onDismissConflict: () => void;
    /** Opens "Articles à sourcer". */
    onOpenSourcing: () => void;
    onQuantityChange: (line: CartLine, quantity: number) => void;
    onDiscountChange: (line: CartLine, type: CartLine['discount_type'], value: string) => void;
    onRemove: (line: CartLine) => void;
    onGlobalDiscountChange: (type: 'none' | 'fixed' | 'percentage', value: string) => void;
    onHold: () => void;
    onNewSale: () => void;
    onOpenHeld: () => void;
    onCheckout: () => void;
    /** Present only in the mobile full-screen cart overlay: returns to the catalogue. */
    onMobileBack?: () => void;
    /** Customer currently attached to the sale (null = walk-in). */
    customer: Customer | null;
    attachingCustomer: boolean;
    onOpenCustomer: () => void;
};

type DiscountUnit = 'percentage' | 'fixed';

function LineDiscount({ line, pending, onChange }: { line: CartLine; pending: boolean; onChange: (type: CartLine['discount_type'], value: string) => void }) {
    const { t } = useI18n();
    const formattedValue = formatEditableNumber(line.discount_value);
    const serverValue = Number(formattedValue) === 0 ? '' : formattedValue;
    const [unit, setUnit] = useState<DiscountUnit>(line.discount_type === 'fixed' ? 'fixed' : 'percentage');
    const [raw, setRaw] = useState(serverValue);

    // Keep local state aligned when the server line changes (e.g. after a reprice).
    useEffect(() => {
        setRaw(serverValue);
        if (line.discount_type !== 'none') setUnit(line.discount_type);
    }, [line.discount_type, serverValue]);

    const commit = (nextUnit: DiscountUnit, nextRaw: string) => {
        const positive = nextRaw !== '' && Number(nextRaw) > 0;
        onChange(positive ? nextUnit : 'none', positive ? nextRaw : '0');
    };

    return (
        <div className="mt-2 flex items-center gap-1.5">
            <span className="flex items-center gap-1 text-[11px] text-ink-muted">{t('pos.discount')} {pending && <Spinner size="xs" />}</span>
            <NumericInput
                value={raw}
                onValueChange={setRaw}
                onCommit={(next) => commit(unit, next)}
                placeholder="0"
                className="h-7 w-14 rounded-field border border-line-strong bg-surface px-2 text-[12px] outline-none focus:border-primary"
            />
            <SegmentedControl
                size="sm"
                ariaLabel={t('pos.discountType')}
                value={unit}
                onChange={(next) => {
                    setUnit(next);
                    commit(next, raw);
                }}
                options={[
                    { value: 'percentage', label: '%' },
                    { value: 'fixed', label: 'DH' },
                ]}
            />
        </div>
    );
}

function GlobalDiscount({ sale, pending, onChange }: { sale: ActiveSale; pending: boolean; onChange: (type: 'none' | 'fixed' | 'percentage', value: string) => void }) {
    const { t } = useI18n();
    const formattedValue = formatEditableNumber(sale.checkout.global_discount_value);
    const serverValue = Number(formattedValue) === 0 ? '' : formattedValue;
    const [unit, setUnit] = useState<DiscountUnit>(sale.checkout.global_discount_type === 'fixed' ? 'fixed' : 'percentage');
    const [raw, setRaw] = useState(serverValue);

    useEffect(() => {
        setRaw(serverValue);
        if (sale.checkout.global_discount_type !== 'none') setUnit(sale.checkout.global_discount_type);
    }, [sale.checkout.global_discount_type, serverValue]);

    const commit = (nextUnit: DiscountUnit, nextRaw: string) => {
        const positive = nextRaw !== '' && Number(nextRaw) > 0;
        onChange(positive ? nextUnit : 'none', positive ? nextRaw : '0');
    };

    return (
        <div className="mb-3 flex items-center gap-2">
            <span className="text-[12px] text-ink-muted">{t('pos.globalDiscount')}</span>
            <SegmentedControl
                size="sm"
                ariaLabel={t('pos.globalDiscountType')}
                value={unit}
                onChange={(next) => {
                    setUnit(next);
                    commit(next, raw);
                }}
                options={[
                    { value: 'percentage', label: '%' },
                    { value: 'fixed', label: 'DH' },
                ]}
            />
            <NumericInput
                value={raw}
                onValueChange={setRaw}
                onCommit={(next) => commit(unit, next)}
                placeholder="0"
                className="h-7 w-16 rounded-field border border-line-strong bg-surface px-2 text-[12px] outline-none focus:border-primary"
            />
            {pending && <Spinner size="xs" className="text-ink-muted" />}
        </div>
    );
}

export default function PosCartPanel({
    sale,
    currencyCode,
    pending,
    canApplyDiscount,
    heldCount,
    stockConflict,
    onDismissConflict,
    onOpenSourcing,
    onQuantityChange,
    onDiscountChange,
    onRemove,
    onGlobalDiscountChange,
    onHold,
    onNewSale,
    onOpenHeld,
    onCheckout,
    onMobileBack,
    customer,
    attachingCustomer,
    onOpenCustomer,
}: Props) {
    const { direction, t } = useI18n();
    const lines = sale?.lines ?? [];
    const summary = sale?.summary;
    const count = lines.reduce((carry, line) => carry + Math.trunc(Number(line.quantity) || 0), 0);
    const blocked = !sale || lines.length === 0 || (sale?.availability_warnings.length ?? 0) > 0;
    const procurementDeficit = sale?.procurement_deficit === true;
    const awaitingProcurement = sale?.awaiting_supplier_procurement === true;
    const toSourceCount = sale?.sourcing_summary?.shortage_line_count ?? 0;
    const canConvert = sale?.sourcing_summary?.can_convert === true;

    // Subtle pulse on the cart counter whenever it grows.
    const [bump, setBump] = useState(false);
    const prevCount = useRef(count);
    useEffect(() => {
        if (count > prevCount.current) {
            setBump(true);
            const timer = window.setTimeout(() => setBump(false), 320);
            prevCount.current = count;
            return () => window.clearTimeout(timer);
        }
        prevCount.current = count;
    }, [count]);

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {/* Header */}
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-line px-4 py-3">
                <div className="flex min-w-0 items-center gap-1.5">
                    {onMobileBack && (
                        <button
                            type="button"
                            onClick={onMobileBack}
                            aria-label={t('pos.backCatalog')}
                            className="flex size-9 shrink-0 items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                        >
                            <svg className="directional-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d={direction === 'rtl' ? 'M10 6l6 6-6 6' : 'M14 6 8 12l6 6'} /></svg>
                        </button>
                    )}
                    <div className="min-w-0">
                        <h2 className="text-base font-semibold text-ink">
                            {t('pos.cart')}{' '}
                            {count > 0 && (
                                <span className={`inline-block text-ink-muted transition-transform duration-200 ${bump ? 'scale-125 text-primary' : ''}`}>({count})</span>
                            )}
                        </h2>
                        <p className="truncate text-[13px] text-ink-muted">{sale ? <bdi>{sale.order_number}</bdi> : t('pos.newSale')}</p>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    <Button size="sm" variant="secondary" onClick={onOpenHeld}>
                        {t('pos.pending')} ({heldCount})
                    </Button>
                    <Button size="sm" variant="secondary" onClick={onNewSale}>
                        <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round"><path d="M12 5v14M5 12h14" /></svg>
                        {t('pos.new')}
                    </Button>
                </div>
            </div>

            {/* Items (internal scroll) */}
            <div className="min-h-0 flex-1 overflow-y-auto">
                {stockConflict && (
                    <div role="alert" className="m-3 rounded-field border border-danger/30 bg-danger-soft px-3 py-2.5 text-[13px] text-danger">
                        <div className="flex items-start justify-between gap-2">
                            <p className="font-semibold">{stockConflict.message}</p>
                            <IconButton label={t('common.close')} size="sm" onClick={onDismissConflict} className="-me-1 -mt-1 text-danger hover:bg-danger-soft">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6 6 18M6 6l12 12" /></svg>
                            </IconButton>
                        </div>
                        <p className="mt-0.5 text-ink-muted">{t('pos.stockConflictHelp')}</p>
                        {stockConflict.lines.length > 0 && (
                            <table className="mt-2 w-full text-[11px] text-ink">
                                <thead>
                                    <tr className="text-ink-muted [&>th]:py-0.5 [&>th]:font-medium">
                                        <th className="text-start">{t('sourcing.article')}</th>
                                        <th className="text-end">{t('pos.requestedQty')}</th>
                                        <th className="text-end">{t('pos.availableQty')}</th>
                                        <th className="text-end">{t('pos.missingQty')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {stockConflict.lines.map((line) => (
                                        <tr key={line.line_id} className="[&>td]:py-0.5">
                                            <td className="truncate">{line.product}</td>
                                            <td className="text-end tabular-nums"><Quantity value={line.requested} /></td>
                                            <td className="text-end tabular-nums"><Quantity value={line.available} /></td>
                                            <td className="text-end font-semibold tabular-nums text-danger"><Quantity value={line.missing} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {toSourceCount > 0 && (
                            <Button size="sm" variant="danger" onClick={onOpenSourcing} className="mt-2">
                                {t('pos.checkAvailability')}
                            </Button>
                        )}
                    </div>
                )}
                {lines.length === 0 ? (
                    <div className="flex h-full flex-col items-center justify-center px-8 text-center">
                        <span className="grid size-11 place-items-center rounded-full bg-sage text-ink-faint">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><path d="M6 6h15l-1.5 9h-12z" /><circle cx="9" cy="20" r="1.4" /><circle cx="18" cy="20" r="1.4" /><path d="M6 6 5 3H3" /></svg>
                        </span>
                        <p className="mt-3 text-sm font-medium text-ink">{t('pos.emptyCart')}</p><p className="mt-1 text-[13px] text-ink-muted">{t('pos.emptyCartHelp')}</p>
                    </div>
                ) : (
                    <ul className="divide-y divide-line">
                        {lines.map((line) => {
                            const removing = pending.removing(line.id);
                            const linePending = pending.line(line.id);
                            return (
                            <li key={line.id} className={`p-3 transition-opacity ${removing ? 'pointer-events-none opacity-45' : ''}`}>
                                <div className="flex gap-2.5">
                                    <div className="size-11 shrink-0 overflow-hidden rounded-field bg-raised">
                                        <ProductImage name={line.description} imageUrl={line.image_url} className="h-full w-full p-1" roundedClassName="rounded-none" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-ink">{line.description}</p>
                                                {(line.sku || line.reference) && (
                                                    <p className="truncate text-[12px] text-ink-muted">{line.sku ?? line.reference}</p>
                                                )}
                                            </div>
                                            <IconButton
                                                label={`${t('pos.remove')} : ${line.description}`}
                                                size="sm"
                                                onClick={() => onRemove(line)}
                                                disabled={removing}
                                                aria-busy={removing || undefined}
                                                className="-me-1.5 -mt-1.5 shrink-0 text-ink-muted hover:bg-danger-soft hover:text-danger"
                                            >
                                                {removing ? (
                                                    <Spinner size="xs" />
                                                ) : (
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13" /></svg>
                                                )}
                                            </IconButton>
                                        </div>

                                        <div className="mt-2 flex items-center justify-between gap-2">
                                            <div className="inline-flex items-center rounded-full border border-line-strong">
                                                <button
                                                    type="button"
                                                    disabled={removing || Number(line.quantity) <= 1}
                                                    onClick={() => onQuantityChange(line, Number(line.quantity) - 1)}
                                                    aria-label={t('pos.decreaseQuantity')}
                                                    className="grid size-9 place-items-center rounded-s-full text-ink-muted transition-soft hover:text-ink disabled:opacity-30"
                                                >
                                                    –
                                                </button>
                                                <span className="flex min-w-8 items-center justify-center gap-1 text-center text-[13px] font-semibold text-ink" aria-live="polite" aria-busy={linePending || undefined}>
                                                    {Math.max(1, Math.trunc(Number(line.quantity) || 1))}
                                                    {linePending && <Spinner size="xs" className="text-ink-faint" />}
                                                </span>
                                                <button
                                                    type="button"
                                                    disabled={removing}
                                                    onClick={() => onQuantityChange(line, Number(line.quantity) + 1)}
                                                    aria-label={t('pos.increaseQuantity')}
                                                    className="grid size-9 place-items-center rounded-e-full text-ink-muted transition-soft hover:text-ink disabled:opacity-30"
                                                >
                                                    +
                                                </button>
                                            </div>
                                            <div className="text-end">
                                                <p className="text-[12px] text-ink-muted">
                                                    <Money value={line.unit_price_incl_tax ?? line.unit_price_excl_tax} currency={currencyCode} /> {t('pos.vatIncluded')} / {t('pos.unit')}
                                                </p>
                                                {line.discount_type !== 'none' && (
                                                    <p className="text-[11px] text-ink-faint line-through"><Money value={line.line_subtotal ?? '0'} currency={currencyCode} /></p>
                                                )}
                                                <p className="text-sm font-semibold text-ink tabular-nums"><Money value={line.line_total ?? '0'} currency={currencyCode} /></p>
                                            </div>
                                        </div>

                                        {canApplyDiscount && (
                                            <LineDiscount line={line} pending={linePending} onChange={(type, value) => onDiscountChange(line, type, value)} />
                                        )}

                                        {line.line_type === 'catalog' && line.sourcing?.requires_sourcing && (
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px]">
                                                <span className={`rounded-full px-1.5 py-0.5 font-semibold ${line.sourcing.resolved ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning'}`}>
                                                    {line.sourcing.resolved ? t('sourcing.available') : t('pos.toSource')}
                                                </span>
                                                <span className="text-ink-muted">
                                                    {t('pos.availableQty')}{' '}
                                                    <Quantity value={line.stock_source ? String(Number(line.stock_source.here) + Number(line.stock_source.elsewhere)) : (line.sourcing.internal_available ?? '0')} />{' '}
                                                    · {t('pos.missingQty')} <Quantity value={line.stock_source?.missing ?? line.sourcing.shortage} />
                                                </span>
                                                <Button size="sm" variant="outline" onClick={onOpenSourcing} className="min-h-8 px-2.5 text-[12px]">
                                                    {t('pos.checkAvailability')}
                                                </Button>
                                            </div>
                                        )}
                                        {line.line_type === 'catalog' &&
                                            (line.procurement || Number(line.stock_source?.elsewhere ?? '0') > 0) && (
                                                <div className="mt-1.5 space-y-0.5 text-[11px]">
                                                    {line.procurement && (
                                                        <p className="text-ink-muted">
                                                            {line.procurement.supplier?.name ?? t('pos.supplier')} · {line.procurement.status_label}
                                                        </p>
                                                    )}
                                                    {/* Only the part another location must supply — never the whole line. */}
                                                    {line.stock_source && Number(line.stock_source.elsewhere) > 0 && (
                                                        <p className="text-warning">
                                                            {Number(line.stock_source.here) > 0 && (
                                                                <>
                                                                    <Quantity value={line.stock_source.here} /> {t('pos.availableHere')} ·{' '}
                                                                </>
                                                            )}
                                                            <Quantity value={line.stock_source.elsewhere} /> {t('pos.fromOtherLocation')}
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                    </div>
                                </div>
                            </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            {/* Sticky totals + actions */}
            <div className="shrink-0 border-t border-line bg-raised p-3.5">
                {(procurementDeficit || awaitingProcurement) && (
                    <div className="mb-3 rounded-field border border-warning/30 bg-warning-soft px-3 py-2 text-[13px] leading-5 text-warning">
                        {procurementDeficit ? (
                            <>
                                {canConvert ? t('pos.sourcingReady') : t('pos.sourcingStockHelp')}
                                {toSourceCount > 0 && (
                                    <Button size="sm" variant="secondary" onClick={onOpenSourcing} className="mt-2 flex">
                                        {canConvert ? t('pos.createOrder') : t('pos.checkAvailability')}
                                    </Button>
                                )}
                            </>
                        ) : (
                            <>
                                {t('pos.supplierPending')}
                                {canConvert && (
                                    <Button size="sm" variant="secondary" onClick={onOpenSourcing} className="mt-2 flex">
                                        {t('pos.createOrder')}
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                )}
                <PosCustomerCard compact customer={customer} attaching={attachingCustomer} canUpdate={false} onOpen={onOpenCustomer} onEdit={onOpenCustomer} onRemove={() => undefined} />
                {canApplyDiscount && sale && lines.length > 0 && <GlobalDiscount sale={sale} pending={pending.globalDiscount} onChange={onGlobalDiscountChange} />}

                <dl className={`space-y-1 text-sm tabular-nums transition-opacity ${pending.recalculating ? 'opacity-70' : ''}`}>
                    <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.grossExcl')}</dt><dd><Money value={summary?.subtotal_excl_tax ?? '0'} currency={currencyCode} /></dd></div>
                    {Number(summary?.line_discount_total ?? 0) > 0 && (
                        <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.itemDiscountsExcl')}</dt><dd className="text-success">- <Money value={summary?.line_discount_total ?? '0'} currency={currencyCode} /></dd></div>
                    )}
                    {Number(summary?.global_discount_amount ?? 0) > 0 && (
                        <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.globalDiscountExcl')}</dt><dd className="text-success">- <Money value={summary?.global_discount_amount ?? '0'} currency={currencyCode} /></dd></div>
                    )}
                    <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.vat')}</dt><dd><Money value={summary?.tax_total ?? '0'} currency={currencyCode} /></dd></div>
                    {Number(summary?.shipping_fee ?? 0) > 0 && (
                        <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.shipping')}</dt><dd><Money value={summary?.shipping_fee ?? '0'} currency={currencyCode} /></dd></div>
                    )}
                    <div className="flex items-baseline justify-between border-t border-line pt-1.5">
                        <dt className="flex items-center gap-2 text-sm font-semibold text-ink">
                            {t('pos.totalIncl')}{pending.recalculating && <InlineLoader label={t('pos.recalculating')} className="text-[11px] font-normal" />}
                        </dt>
                        <dd className="metric-value text-xl font-semibold text-ink"><Money value={summary?.total ?? '0'} currency={currencyCode} /></dd>
                    </div>
                </dl>

                <div className="mt-3 grid grid-cols-[auto_1fr] gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="lg"
                        disabled={!sale || lines.length === 0}
                        loading={pending.hold}
                        loadingText={t('pos.holding')}
                        onClick={onHold}
                    >
                        {t('pos.pending')}
                    </Button>
                    <Button type="button" size="lg" disabled={blocked || pending.recalculating} onClick={onCheckout}>
                        {t('pos.checkout')}
                        <span aria-hidden="true">{direction === 'rtl' ? '←' : '→'}</span>
                    </Button>
                </div>
            </div>
        </div>
    );
}
