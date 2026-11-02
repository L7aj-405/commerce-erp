import SearchInput from '@/components/ui/SearchInput';
import { useI18n } from '@/i18n';
import { formatInteger, formatQuantity } from '@/utils/format';
import type { ReactNode } from 'react';

/**
 * Product catalogue + selected transfer lines, side by side.
 *
 * Shared by "Nouveau transfert" (immediate stock transfer) and the transfer
 * REQUEST create/edit form so both behave the same way:
 *  - desktop (lg+): balanced two columns — searchable catalogue on the left,
 *    selected lines on the right, each list scrolling inside its own panel so
 *    the selection is always on screen next to the search;
 *  - mobile: stacked, with a sticky summary bar ("3 articles · 12 unités")
 *    that jumps to the selection.
 * Quantities shown here are guidance; the server re-validates every submit.
 */

export type PickerStock = {
    on_hand: string;
    reserved: string;
    available: string;
    committed?: string;
    transferable?: string;
    destination_on_hand?: string;
};
export type PickerItem = {
    product_variant_id: number;
    product_name: string | null;
    label: string | null;
    sku: string | null;
    reference: string | null;
    brand: string | null;
    stock: PickerStock | null;
};
export type PickerLine = PickerItem & {
    key: string;
    quantity: string;
    /** Read-only (customer-order quantity): no edit, no removal. */
    protected?: boolean;
    badge?: string | null;
    hint?: string | null;
    error?: string;
};

type Props = {
    sourceSelected: boolean;
    search: string;
    onSearch: (value: string) => void;
    searching: boolean;
    results: PickerItem[];
    lines: PickerLine[];
    onAdd: (item: PickerItem) => void;
    onQuantity: (key: string, value: string) => void;
    onRemove: (key: string) => void;
    /** Which stock figure caps a line: plain `available` or `transferable` (net of other requests). */
    limitField?: 'available' | 'transferable';
    step?: number | 'any';
    listError?: string;
    footer?: ReactNode;
    selectedTitle?: string;
};

const SELECTED_ID = 'transfer-selected-lines';

export function limitOf(stock: PickerStock | null | undefined, field: 'available' | 'transferable'): number | null {
    if (!stock) return null;
    const raw = field === 'transferable' ? (stock.transferable ?? stock.available) : stock.available;
    const value = Number(raw);
    return Number.isFinite(value) ? Math.max(0, value) : null;
}

export default function TransferLinePicker({
    sourceSelected,
    search,
    onSearch,
    searching,
    results,
    lines,
    onAdd,
    onQuantity,
    onRemove,
    limitField = 'available',
    step = 1,
    listError,
    footer,
    selectedTitle,
}: Props) {
    const { t } = useI18n();
    const selected = new Map(lines.filter((l) => !l.protected).map((l) => [l.product_variant_id, l]));
    const totalUnits = lines.reduce((sum, l) => sum + (Number(l.quantity) || 0), 0);
    const limitLabel = limitField === 'transferable' ? t('inventory.picker.transferable') : t('inventory.transferCreate.available');

    const focusLine = (variantId: number) => {
        const input = document.getElementById(`line-qty-${variantId}`) as HTMLInputElement | null;
        input?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        input?.focus();
        input?.select();
    };

    return (
        <div className="grid gap-4 lg:grid-cols-2 lg:items-start">
            {/* ---------------- catalogue ---------------- */}
            <section className="flex min-w-0 flex-col rounded-card border border-line bg-surface shadow-soft" aria-labelledby="picker-catalogue-title">
                <header className="border-b border-line p-4">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h3 id="picker-catalogue-title" className="text-sm font-semibold text-ink">{t('inventory.picker.catalogue')}</h3>
                        {sourceSelected && results.length > 0 && (
                            <span className="text-xs text-ink-faint">{t('inventory.picker.resultCount').replace('{count}', formatInteger(results.length))}</span>
                        )}
                    </div>
                    <SearchInput value={search} onChange={onSearch} searching={searching} placeholder={t('inventory.transferCreate.search')} />
                </header>

                <div className="max-h-[22rem] min-h-[10rem] overflow-y-auto overscroll-contain lg:max-h-[clamp(18rem,calc(100vh-37rem),34rem)]" aria-busy={searching || undefined}>
                    {!sourceSelected ? (
                        <PanelEmpty title={t('inventory.transferCreate.selectSourceFirst')} description={t('inventory.transferCreate.sourceAvailabilityHelp')} />
                    ) : searching && results.length === 0 ? (
                        <ul className="divide-y divide-line" aria-hidden="true">
                            {[0, 1, 2, 3].map((i) => (
                                <li key={i} className="flex items-center gap-3 px-4 py-3">
                                    <div className="min-w-0 flex-1 space-y-2">
                                        <div className="h-3 w-2/3 animate-pulse rounded bg-raised" />
                                        <div className="h-2.5 w-1/3 animate-pulse rounded bg-raised" />
                                    </div>
                                    <div className="h-8 w-16 animate-pulse rounded-field bg-raised" />
                                </li>
                            ))}
                        </ul>
                    ) : results.length === 0 ? (
                        <PanelEmpty
                            title={search ? t('inventory.transferCreate.noResult') : t('inventory.transferCreate.noStock')}
                            description={search ? t('inventory.transferCreate.tryAnother') : t('inventory.transferCreate.noTransferable')}
                        />
                    ) : (
                        <ul className={`divide-y divide-line transition-opacity ${searching ? 'opacity-60' : ''}`}>
                            {results.map((item) => {
                                const line = selected.get(item.product_variant_id);
                                const limit = limitOf(item.stock, limitField);
                                const exhausted = limit !== null && limit <= 0;

                                return (
                                    <li key={item.product_variant_id} className="flex items-center gap-3 px-4 py-2.5 hover:bg-raised/60">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-ink">{item.product_name ?? '—'}</p>
                                            <p className="truncate text-xs text-ink-muted">
                                                {item.label ?? t('products.mainVariant')} · <bdi>{item.sku ?? '—'}</bdi>
                                                {item.brand ? ` · ${item.brand}` : ''}
                                            </p>
                                            {item.stock && <StockHint stock={item.stock} limitField={limitField} />}
                                        </div>
                                        <div className="shrink-0 text-end">
                                            <p className="text-[10px] uppercase tracking-wide text-ink-faint">{limitLabel}</p>
                                            <p className={`text-sm font-semibold tabular-nums ${exhausted ? 'text-warning' : 'text-ink'}`}>{limit === null ? '—' : formatQuantity(limit)}</p>
                                        </div>
                                        {line ? (
                                            <button
                                                type="button"
                                                onClick={() => focusLine(item.product_variant_id)}
                                                className="inline-flex h-8 min-w-[4.5rem] shrink-0 items-center justify-center rounded-field bg-sage px-2 text-xs font-medium text-primary"
                                                title={t('inventory.picker.editQuantity')}
                                            >
                                                ✓ {formatQuantity(line.quantity || 0)}
                                            </button>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => onAdd(item)}
                                                disabled={exhausted}
                                                className="inline-flex h-8 min-w-[4.5rem] shrink-0 items-center justify-center rounded-field border border-primary/35 px-2 text-xs font-medium text-primary transition-soft hover:bg-sage disabled:cursor-not-allowed disabled:opacity-45"
                                            >
                                                + {t('pos.add')}
                                            </button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </section>

            {/* mobile: summary bar always reachable */}
            {lines.length > 0 && (
                <a
                    href={`#${SELECTED_ID}`}
                    className="sticky bottom-3 z-20 flex items-center justify-between gap-3 rounded-full border border-primary/30 bg-surface px-4 py-2.5 text-sm shadow-soft lg:hidden"
                >
                    <span className="font-medium text-ink">
                        {t('inventory.picker.summary').replace('{lines}', formatInteger(lines.length)).replace('{units}', formatQuantity(totalUnits))}
                    </span>
                    <span className="text-xs font-semibold text-primary">{t('inventory.picker.viewSelection')} ↓</span>
                </a>
            )}

            {/* ---------------- selected lines ---------------- */}
            <section id={SELECTED_ID} className="flex min-w-0 scroll-mt-24 flex-col rounded-card border border-line bg-surface shadow-soft lg:sticky lg:top-20" aria-labelledby="picker-selected-title">
                <header className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                    <h3 id="picker-selected-title" className="min-w-0 truncate text-sm font-semibold text-ink">{selectedTitle ?? t('inventory.transferCreate.lines')}</h3>
                    <div className="flex shrink-0 items-center gap-1.5 whitespace-nowrap text-xs">
                        <span className="rounded-full bg-raised px-2.5 py-1 font-medium text-ink-muted">{t('inventory.picker.lineCount').replace('{count}', formatInteger(lines.length))}</span>
                        <span className="rounded-full bg-sage px-2.5 py-1 font-semibold tabular-nums text-primary">{t('inventory.picker.unitCount').replace('{units}', formatQuantity(totalUnits))}</span>
                    </div>
                </header>
                {listError && <p className="border-b border-line bg-danger-soft px-4 py-2 text-sm text-danger">{listError}</p>}

                <div className="max-h-[26rem] overflow-y-auto overscroll-contain lg:max-h-[clamp(18rem,calc(100vh-37rem),34rem)]">
                    {lines.length === 0 ? (
                        <PanelEmpty title={t('inventory.transferCreate.noProduct')} description={t('inventory.transferCreate.noProductHelp')} />
                    ) : (
                        <ul className="divide-y divide-line">
                            {lines.map((line) => {
                                const limit = limitOf(line.stock, limitField);
                                const qty = Number(line.quantity);
                                const over = !line.protected && limit !== null && Number.isFinite(qty) && qty > limit;
                                return (
                                    <li key={line.key} className="px-4 py-3">
                                        <div className="flex items-start gap-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium text-ink">{line.product_name ?? '—'}</p>
                                                <p className="truncate text-xs text-ink-muted">
                                                    {line.label ?? t('products.mainVariant')} · <bdi>{line.sku ?? '—'}</bdi>
                                                </p>
                                                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                    {line.badge && <span className="rounded-full bg-raised px-2 py-0.5 text-[11px] font-medium text-ink-muted">{line.badge}</span>}
                                                    {line.protected && <span className="rounded-full bg-warning-soft px-2 py-0.5 text-[11px] font-medium text-warning">{t('inventory.picker.protected')}</span>}
                                                    {line.stock && !line.protected && <StockHint stock={line.stock} limitField={limitField} />}
                                                </div>
                                            </div>
                                            {line.protected ? (
                                                <span className="shrink-0 pt-1 text-sm font-semibold tabular-nums text-ink">{formatQuantity(line.quantity)}</span>
                                            ) : (
                                                <div className="flex shrink-0 items-center gap-1.5">
                                                    <QuantityStepper
                                                        id={`line-qty-${line.product_variant_id}`}
                                                        value={line.quantity}
                                                        step={step}
                                                        invalid={over || Boolean(line.error)}
                                                        onChange={(value) => onQuantity(line.key, value)}
                                                        label={`${t('inventory.transferCreate.quantity')} — ${line.product_name ?? ''}`}
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => onRemove(line.key)}
                                                        className="grid size-9 place-items-center rounded-field text-ink-faint transition-soft hover:bg-danger-soft hover:text-danger"
                                                        aria-label={`${t('docProfile.remove')} — ${line.product_name ?? ''}`}
                                                        title={t('docProfile.remove')}
                                                    >
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></svg>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                        {over && (
                                            <p className="mt-1.5 text-xs text-warning">
                                                {t('inventory.picker.overLimit').replace('{limit}', formatQuantity(limit ?? 0))}
                                            </p>
                                        )}
                                        {line.hint && <p className="mt-1.5 text-xs text-ink-muted">{line.hint}</p>}
                                        {line.error && <p className="mt-1.5 text-xs text-danger">{line.error}</p>}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
                {footer && <footer className="border-t border-line bg-raised/60 p-4">{footer}</footer>}
            </section>
        </div>
    );
}

function StockHint({ stock, limitField }: { stock: PickerStock; limitField: 'available' | 'transferable' }) {
    const { t } = useI18n();
    const parts = [t('inventory.picker.onHand').replace('{qty}', formatQuantity(stock.on_hand))];
    if (Number(stock.reserved) > 0) parts.push(t('inventory.picker.reserved').replace('{qty}', formatQuantity(stock.reserved)));
    if (limitField === 'transferable' && Number(stock.committed ?? 0) > 0) parts.push(t('inventory.picker.promised').replace('{qty}', formatQuantity(stock.committed ?? 0)));
    if (stock.destination_on_hand !== undefined) parts.push(t('inventory.picker.atDestination').replace('{qty}', formatQuantity(stock.destination_on_hand)));
    return <span className="text-[11px] text-ink-faint">{parts.join(' · ')}</span>;
}

function QuantityStepper({ id, value, onChange, step, invalid, label }: { id: string; value: string; onChange: (v: string) => void; step: number | 'any'; invalid: boolean; label: string }) {
    const delta = step === 'any' ? 1 : step;
    const bump = (sign: 1 | -1) => {
        const current = Number(value) || 0;
        const next = Math.max(delta, current + sign * delta);
        onChange(String(Math.round(next * 10000) / 10000));
    };
    return (
        <div className={`flex h-9 items-stretch overflow-hidden rounded-field border ${invalid ? 'border-warning' : 'border-line-strong'} bg-surface`}>
            <button type="button" onClick={() => bump(-1)} className="w-8 text-ink-muted hover:bg-raised" aria-label="−" tabIndex={-1}>−</button>
            <input
                id={id}
                type="number"
                inputMode="decimal"
                min={0}
                step={step}
                value={value}
                aria-label={label}
                aria-invalid={invalid || undefined}
                onChange={(e) => onChange(e.target.value)}
                className="w-16 border-x border-line bg-transparent px-1 text-center text-sm tabular-nums outline-none focus:bg-sage/40"
            />
            <button type="button" onClick={() => bump(1)} className="w-8 text-ink-muted hover:bg-raised" aria-label="+" tabIndex={-1}>+</button>
        </div>
    );
}

function PanelEmpty({ title, description }: { title: string; description: string }) {
    return (
        <div className="px-6 py-10 text-center">
            <p className="text-sm font-medium text-ink">{title}</p>
            <p className="mx-auto mt-1 max-w-sm text-sm text-ink-muted">{description}</p>
        </div>
    );
}
