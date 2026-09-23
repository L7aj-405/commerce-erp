import { Button } from '@/components/ui/Button';
import { usePendingKeys } from '@/hooks/usePendingKeys';
import { useSerializedByKey } from '@/hooks/useSerializedByKey';
import { formatMoney, formatQuantity } from '@/utils/format';
import { router } from '@inertiajs/react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';

export type OLine = {
    id: number;
    line_type: 'catalog' | 'custom';
    product_variant_id: number | null;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    unit_label: string | null;
    quantity: string;
    unit_price_excl_tax: string;
    unit_price_incl_tax: string;
    tax_name: string | null;
    tax_rate: string;
    tax_unresolved: boolean;
    discount_type: 'none' | 'fixed' | 'percentage';
    discount_value: string;
    discount_amount: string;
    discount_amount_ttc?: string;
    taxable_amount: string;
    tax_amount: string;
    total_incl_tax: string;
    allocations: { quantity: string; warehouse: { name: string; code: string } }[];
};
type TaxRate = { id: number; name: string; rate: string };
type SearchRow = {
    id: number;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    barcode: string | null;
    brand: string | null;
    unit_label: string | null;
    unit_price_excl_tax: string | null;
    unit_price_incl_tax: string | null;
    tax_rate: string;
    tax_name: string | null;
    tax_unresolved: boolean;
    stock_available: string;
};
type Props = {
    orderId: number;
    currency: string;
    lines: OLine[];
    searchUrl: string;
    taxRates: TaxRate[];
    canOverridePrice: boolean;
    canApplyDiscount: boolean;
};
type Draft = { quantity: string; price_mode: 'ht' | 'ttc'; unit_price: string; discount_value: string; discount_unit: '%' | 'DH'; tax_rate_id: string };

const RELOAD_ONLY = ['order', 'errors', 'can', 'flash'];

function matchTaxId(taxRates: TaxRate[], rate: string): string {
    const target = Number(rate).toFixed(4);
    return taxRates.find((t) => Number(t.rate).toFixed(4) === target)?.id.toString() ?? '';
}

function draftFromLine(l: OLine, taxRates: TaxRate[]): Draft {
    return {
        quantity: integerQuantity(l.quantity),
        price_mode: 'ht',
        unit_price: l.unit_price_excl_tax,
        discount_value: l.discount_type === 'none' ? '' : l.discount_value,
        discount_unit: l.discount_type === 'fixed' ? 'DH' : '%',
        tax_rate_id: matchTaxId(taxRates, l.tax_rate),
    };
}

function integerQuantity(value: string): string {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? String(Math.trunc(parsed)) : value;
}

export default function OrderLineGrid({ orderId, currency, lines, searchUrl, taxRates, canOverridePrice, canApplyDiscount }: Props) {
    const pending = usePendingKeys();
    const serialized = useSerializedByKey();
    const timers = useRef<Record<number, number>>({});
    const draftsRef = useRef<Record<number, Draft>>({});
    const [drafts, setDrafts] = useState<Record<number, Draft>>(() => Object.fromEntries(lines.map((l) => [l.id, draftFromLine(l, taxRates)])));
    const [rowError, setRowError] = useState<Record<number, string>>({});
    const [adderOpen, setAdderOpen] = useState(false);
    draftsRef.current = drafts;

    useEffect(() => {
        setDrafts((cur) => {
            const next: Record<number, Draft> = {};
            for (const l of lines) {
                next[l.id] = serialized.isBusy(`line:${l.id}`) ? cur[l.id] ?? draftFromLine(l, taxRates) : draftFromLine(l, taxRates);
            }
            return next;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lines]);

    const patchDraft = (id: number, patch: Partial<Draft>) => setDrafts((c) => ({ ...c, [id]: { ...c[id], ...patch } }));
    const schedule = (id: number) => {
        window.clearTimeout(timers.current[id]);
        timers.current[id] = window.setTimeout(() => commit(id), 500);
    };
    const commit = (id: number) => {
        window.clearTimeout(timers.current[id]);
        serialized.enqueue(`line:${id}`, () => submit(id));
    };

    function submit(id: number): Promise<unknown> {
        const line = lines.find((l) => l.id === id);
        const d = draftsRef.current[id];
        if (!line || !d) return Promise.resolve();
        const hasDisc = canApplyDiscount && d.discount_value.trim() !== '' && Number(d.discount_value) > 0;
        const body: Record<string, string | number | null> = {
            line_type: line.line_type,
            quantity: d.quantity.trim() === '' ? '0' : d.quantity.trim(),
            discount_type: hasDisc ? (d.discount_unit === 'DH' ? 'fixed' : 'percentage') : 'none',
            discount_value: hasDisc ? d.discount_value.trim() : '0',
        };
        if (line.line_type === 'catalog') {
            body.product_variant_id = line.product_variant_id;
            if (canOverridePrice && d.unit_price.trim() !== '') body.unit_price_excl_tax = d.unit_price.trim();
            // Only an unresolved-tax catalogue line accepts a tax choice from here;
            // a Product-configured rate always wins server-side.
            if (line.tax_unresolved && d.tax_rate_id) body.tax_rate_id = Number(d.tax_rate_id);
        } else {
            body.description = line.product_name;
            body.reference = line.reference;
            body.unit_label = line.unit_label;
            body.tax_rate_id = d.tax_rate_id ? Number(d.tax_rate_id) : null;
            body.price_input_mode = d.price_mode;
            body.unit_price = d.unit_price.trim() === '' ? '0' : d.unit_price.trim();
        }

        pending.start(`line:${id}`);
        return new Promise((resolve) => {
            router.patch(`/sales/orders/${orderId}/lines/${id}`, body, {
                preserveScroll: true,
                preserveState: true,
                only: RELOAD_ONLY,
                onError: (e) => setRowError((c) => ({ ...c, [id]: Object.values(e)[0] ?? 'Modification refusée.' })),
                onSuccess: () => setRowError((c) => omit(c, id)),
                onFinish: () => {
                    pending.stop(`line:${id}`);
                    resolve(undefined);
                },
            });
        });
    }

    function removeLine(id: number) {
        void pending.run(`rm:${id}`, () =>
            new Promise<void>((resolve) => {
                router.delete(`/sales/orders/${orderId}/lines/${id}`, {
                    preserveScroll: true,
                    preserveState: true,
                    only: RELOAD_ONLY,
                    onError: (e) => setRowError((c) => ({ ...c, [id]: Object.values(e)[0] ?? 'Suppression refusée.' })),
                    onFinish: () => resolve(),
                });
            }),
        );
    }

    function addArticle(body: Record<string, string | number | null>, keepOpen = false) {
        return pending.run('line:add', () =>
            new Promise<void>((resolve) => {
                router.post(`/sales/orders/${orderId}/lines`, body, {
                    preserveScroll: true,
                    preserveState: true,
                    only: RELOAD_ONLY,
                    onError: (e) => setRowError((c) => ({ ...c, 0: Object.values(e)[0] ?? 'Ajout refusé.' })),
                    onSuccess: () => {
                        setRowError((c) => omit(c, 0));
                        if (!keepOpen) setAdderOpen(false);
                    },
                    onFinish: () => resolve(),
                });
            }),
        );
    }

    return (
        <div className="space-y-3">
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full min-w-[820px] border-collapse text-sm">
                    <thead>
                        <tr className="border-b border-line-strong text-left text-xs uppercase tracking-wide text-ink-muted [&>th]:px-2 [&>th]:py-2 [&>th]:font-medium">
                            <th>Article</th>
                            <th className="w-16 text-right">Qté</th>
                            <th className="w-44">PU HT</th>
                            <th className="w-32">Remise TTC</th>
                            <th className="w-20 text-right">TVA</th>
                            <th className="w-32 text-right">Total TTC</th>
                            <th className="w-8" />
                        </tr>
                    </thead>
                    <tbody className="[&>tr>td]:border-b [&>tr>td]:border-line [&>tr>td]:px-2 [&>tr>td]:py-2 [&>tr>td]:align-top">
                        {lines.map((line) => {
                            const d = drafts[line.id] ?? draftFromLine(line, taxRates);
                            const busy = pending.isPending(`line:${line.id}`) || pending.isPending(`rm:${line.id}`);
                            const source = line.allocations.map((a) => `${formatQuantity(a.quantity)} ${a.warehouse.name}`).join(' · ');
                            const priceEditable = line.line_type === 'custom' || canOverridePrice;
                            return (
                                <tr key={line.id} className={busy ? 'opacity-60' : undefined}>
                                    <td>
                                        <span className="font-medium text-ink">{line.product_name}</span>
                                        {line.variant_name && <span className="block text-xs text-ink-muted">{line.variant_name}</span>}
                                        <span className="block text-xs text-ink-faint">
                                            <span className="mr-1 rounded bg-raised px-1 text-[10px] uppercase tracking-wide">
                                                {line.line_type === 'catalog' ? 'Catalogue' : 'Personnalisé'}
                                            </span>
                                            {[line.reference ?? line.sku, line.unit_label].filter(Boolean).join(' · ')}
                                            {source && <span className="block">Source : {source}</span>}
                                        </span>
                                        {line.tax_unresolved && (
                                            <p className="mt-1 text-xs text-warning">TVA à définir avant confirmation</p>
                                        )}
                                        {rowError[line.id] && <p className="mt-1 text-xs text-danger">{rowError[line.id]}</p>}
                                    </td>
                                    <td className="text-right">
                                        <input
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            step={1}
                                            disabled={busy}
                                            value={d.quantity}
                                            onChange={(e) => {
                                                patchDraft(line.id, { quantity: e.target.value });
                                                schedule(line.id);
                                            }}
                                            onBlur={() => commit(line.id)}
                                            onKeyDown={(e) => e.key === 'Enter' && commit(line.id)}
                                            className="w-14 rounded-field border border-line-strong px-2 py-1 text-right tabular-nums"
                                        />
                                    </td>
                                    <td>
                                        {priceEditable ? (
                                            <>
                                                <div className="flex items-center gap-1">
                                                    <input
                                                        inputMode="decimal"
                                                        disabled={busy}
                                                        value={d.unit_price}
                                                        onChange={(e) => {
                                                            patchDraft(line.id, { unit_price: e.target.value });
                                                            schedule(line.id);
                                                        }}
                                                        onBlur={() => commit(line.id)}
                                                        className="w-24 rounded-field border border-line-strong px-2 py-1 text-right tabular-nums"
                                                    />
                                                    {line.line_type === 'custom' && (
                                                        <select
                                                            disabled={busy}
                                                            value={d.price_mode}
                                                            onChange={(e) => {
                                                                const mode = e.target.value as 'ht' | 'ttc';
                                                                patchDraft(line.id, {
                                                                    price_mode: mode,
                                                                    unit_price: mode === 'ttc' ? line.unit_price_incl_tax : line.unit_price_excl_tax,
                                                                });
                                                                commit(line.id);
                                                            }}
                                                            className="rounded-field border border-line-strong px-1 py-1 text-xs"
                                                        >
                                                            <option value="ht">HT</option>
                                                            <option value="ttc">TTC</option>
                                                        </select>
                                                    )}
                                                </div>
                                                <span className="mt-0.5 block text-right text-xs text-ink-faint">
                                                    {d.price_mode === 'ht'
                                                        ? `TTC ${formatMoney(line.unit_price_incl_tax, currency)}`
                                                        : `HT ${formatMoney(line.unit_price_excl_tax, currency)}`}
                                                </span>
                                            </>
                                        ) : (
                                            <span className="block text-right tabular-nums text-ink">{formatMoney(line.unit_price_excl_tax, currency)}</span>
                                        )}
                                    </td>
                                    <td>
                                        {canApplyDiscount ? (
                                            <div className="flex items-center gap-1">
                                                <input
                                                    inputMode="decimal"
                                                    disabled={busy}
                                                    placeholder="0"
                                                    value={d.discount_value}
                                                    onChange={(e) => {
                                                        patchDraft(line.id, { discount_value: e.target.value });
                                                        schedule(line.id);
                                                    }}
                                                    onBlur={() => commit(line.id)}
                                                    className="w-14 rounded-field border border-line-strong px-2 py-1 text-right tabular-nums"
                                                />
                                                <select
                                                    disabled={busy}
                                                    value={d.discount_unit}
                                                    onChange={(e) => {
                                                        patchDraft(line.id, { discount_unit: e.target.value as '%' | 'DH' });
                                                        commit(line.id);
                                                    }}
                                                    className="rounded-field border border-line-strong px-1 py-1 text-xs"
                                                >
                                                    <option value="%">%</option>
                                                    <option value="DH">DH</option>
                                                </select>
                                            </div>
                                        ) : (
                                            <span className="block text-right tabular-nums text-ink-muted">
                                                {Number(line.discount_amount_ttc ?? line.discount_amount) > 0 ? `- ${formatMoney(line.discount_amount_ttc ?? line.discount_amount, currency)}` : '—'}
                                            </span>
                                        )}
                                    </td>
                                    <td className="text-right text-xs tabular-nums text-ink-muted">
                                        {line.line_type === 'custom' || line.tax_unresolved ? (
                                            <select
                                                disabled={busy}
                                                value={d.tax_rate_id}
                                                onChange={(e) => {
                                                    patchDraft(line.id, { tax_rate_id: e.target.value });
                                                    commit(line.id);
                                                }}
                                                className={`w-full rounded-field border px-1 py-1 text-xs ${
                                                    line.tax_unresolved ? 'border-warning text-warning' : 'border-line-strong'
                                                }`}
                                            >
                                                <option value="">{line.tax_unresolved && line.line_type === 'catalog' ? 'À définir' : '0 %'}</option>
                                                {taxRates.map((t) => (
                                                    <option key={t.id} value={t.id}>
                                                        {formatQuantity(t.rate)} %
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <>
                                                {formatQuantity(line.tax_rate)} %{line.tax_name && <span className="block text-ink-faint">{line.tax_name}</span>}
                                            </>
                                        )}
                                    </td>
                                    <td className="text-right font-medium tabular-nums text-ink">{formatMoney(line.total_incl_tax, currency)}</td>
                                    <td className="text-right">
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => removeLine(line.id)}
                                            className="text-ink-faint hover:text-danger disabled:opacity-40"
                                            aria-label="Retirer la ligne"
                                        >
                                            ✕
                                        </button>
                                    </td>
                                </tr>
                            );
                        })}
                        {lines.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-2 py-8 text-center text-ink-muted">
                                    Aucun article.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* Mobile: stacked editable line cards */}
            <ul className="space-y-3 md:hidden">
                {lines.map((line) => {
                    const d = drafts[line.id] ?? draftFromLine(line, taxRates);
                    const busy = pending.isPending(`line:${line.id}`) || pending.isPending(`rm:${line.id}`);
                    const source = line.allocations.map((a) => `${formatQuantity(a.quantity)} ${a.warehouse.name}`).join(' · ');
                    const priceEditable = line.line_type === 'custom' || canOverridePrice;
                    return (
                        <li key={line.id} className={`rounded-card border border-line bg-surface p-3 ${busy ? 'opacity-60' : ''}`}>
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <span className="mr-1 rounded bg-raised px-1 text-[10px] uppercase tracking-wide text-ink-faint">
                                        {line.line_type === 'catalog' ? 'Catalogue' : 'Personnalisé'}
                                    </span>
                                    <p className="truncate font-medium text-ink">{line.product_name}</p>
                                    {line.variant_name && <p className="truncate text-xs text-ink-muted">{line.variant_name}</p>}
                                    <p className="truncate text-xs text-ink-faint">
                                        {[line.reference ?? line.sku, line.unit_label].filter(Boolean).join(' · ')}
                                    </p>
                                    {source && <p className="text-xs text-ink-faint">Source : {source}</p>}
                                    {line.tax_unresolved && <p className="mt-1 text-xs text-warning">TVA à définir avant confirmation</p>}
                                    {rowError[line.id] && <p className="mt-1 text-xs text-danger">{rowError[line.id]}</p>}
                                </div>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => removeLine(line.id)}
                                    aria-label="Retirer la ligne"
                                    className="flex size-9 shrink-0 items-center justify-center rounded-field text-ink-faint hover:text-danger disabled:opacity-40"
                                >
                                    ✕
                                </button>
                            </div>

                            <div className="mt-3 grid grid-cols-2 gap-x-2 gap-y-2.5 text-xs text-ink-muted">
                                <label className="block">
                                    Qté
                                    <input
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        step={1}
                                        disabled={busy}
                                        value={d.quantity}
                                        onChange={(e) => {
                                            patchDraft(line.id, { quantity: e.target.value });
                                            schedule(line.id);
                                        }}
                                        onBlur={() => commit(line.id)}
                                        onKeyDown={(e) => e.key === 'Enter' && commit(line.id)}
                                        className="mt-0.5 h-9 w-full rounded-field border border-line-strong px-2 text-right text-sm tabular-nums text-ink"
                                    />
                                </label>

                                <label className="block">
                                    TVA
                                    {line.line_type === 'custom' || line.tax_unresolved ? (
                                        <select
                                            disabled={busy}
                                            value={d.tax_rate_id}
                                            onChange={(e) => {
                                                patchDraft(line.id, { tax_rate_id: e.target.value });
                                                commit(line.id);
                                            }}
                                            className={`mt-0.5 h-9 w-full rounded-field border px-2 text-sm ${
                                                line.tax_unresolved ? 'border-warning text-warning' : 'border-line-strong text-ink'
                                            }`}
                                        >
                                            <option value="">{line.tax_unresolved && line.line_type === 'catalog' ? 'À définir' : '0 %'}</option>
                                            {taxRates.map((t) => (
                                                <option key={t.id} value={t.id}>
                                                    {formatQuantity(t.rate)} %
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <p className="mt-1.5 text-sm text-ink">
                                            {formatQuantity(line.tax_rate)} % {line.tax_name && <span className="block text-xs text-ink-faint">{line.tax_name}</span>}
                                        </p>
                                    )}
                                </label>

                                <label className="col-span-2 block">
                                    PU HT
                                    {priceEditable ? (
                                        <>
                                            <div className="mt-0.5 flex items-center gap-1.5">
                                                <input
                                                    inputMode="decimal"
                                                    disabled={busy}
                                                    value={d.unit_price}
                                                    onChange={(e) => {
                                                        patchDraft(line.id, { unit_price: e.target.value });
                                                        schedule(line.id);
                                                    }}
                                                    onBlur={() => commit(line.id)}
                                                    className="h-9 w-full rounded-field border border-line-strong px-2 text-right text-sm tabular-nums text-ink"
                                                />
                                                {line.line_type === 'custom' && (
                                                    <select
                                                        disabled={busy}
                                                        value={d.price_mode}
                                                        onChange={(e) => {
                                                            const mode = e.target.value as 'ht' | 'ttc';
                                                            patchDraft(line.id, {
                                                                price_mode: mode,
                                                                unit_price: mode === 'ttc' ? line.unit_price_incl_tax : line.unit_price_excl_tax,
                                                            });
                                                            commit(line.id);
                                                        }}
                                                        className="h-9 shrink-0 rounded-field border border-line-strong px-1.5 text-xs text-ink"
                                                    >
                                                        <option value="ht">HT</option>
                                                        <option value="ttc">TTC</option>
                                                    </select>
                                                )}
                                            </div>
                                            <span className="mt-1 block text-right text-xs text-ink-faint">
                                                {d.price_mode === 'ht'
                                                    ? `TTC ${formatMoney(line.unit_price_incl_tax, currency)}`
                                                    : `HT ${formatMoney(line.unit_price_excl_tax, currency)}`}
                                            </span>
                                        </>
                                    ) : (
                                        <p className="mt-1.5 text-right text-sm tabular-nums text-ink">{formatMoney(line.unit_price_excl_tax, currency)}</p>
                                    )}
                                </label>

                                <label className="col-span-2 block">
                                    Remise TTC
                                    {canApplyDiscount ? (
                                        <div className="mt-0.5 flex items-center gap-1.5">
                                            <input
                                                inputMode="decimal"
                                                disabled={busy}
                                                placeholder="0"
                                                value={d.discount_value}
                                                onChange={(e) => {
                                                    patchDraft(line.id, { discount_value: e.target.value });
                                                    schedule(line.id);
                                                }}
                                                onBlur={() => commit(line.id)}
                                                className="h-9 w-full rounded-field border border-line-strong px-2 text-right text-sm tabular-nums text-ink"
                                            />
                                            <select
                                                disabled={busy}
                                                value={d.discount_unit}
                                                onChange={(e) => {
                                                    patchDraft(line.id, { discount_unit: e.target.value as '%' | 'DH' });
                                                    commit(line.id);
                                                }}
                                                className="h-9 shrink-0 rounded-field border border-line-strong px-1.5 text-xs text-ink"
                                            >
                                                <option value="%">%</option>
                                                <option value="DH">DH</option>
                                            </select>
                                        </div>
                                    ) : (
                                        <p className="mt-1.5 text-right text-sm tabular-nums text-ink-muted">
                                            {Number(line.discount_amount_ttc ?? line.discount_amount) > 0 ? `- ${formatMoney(line.discount_amount_ttc ?? line.discount_amount, currency)}` : '—'}
                                        </p>
                                    )}
                                </label>
                            </div>

                            <div className="mt-3 flex items-center justify-between border-t border-line pt-2.5 text-sm font-semibold text-ink">
                                <span>Total TTC</span>
                                <span className="tabular-nums">{formatMoney(line.total_incl_tax, currency)}</span>
                            </div>
                        </li>
                    );
                })}
                {lines.length === 0 && <li className="rounded-card border border-dashed border-line-strong px-3 py-8 text-center text-sm text-ink-muted">Aucun article.</li>}
            </ul>

            {adderOpen ? (
                <ArticleAdder
                    searchUrl={searchUrl}
                    taxRates={taxRates}
                    currency={currency}
                    canApplyDiscount={canApplyDiscount}
                    adding={pending.isPending('line:add')}
                    onAdd={addArticle}
                    onClose={() => setAdderOpen(false)}
                />
            ) : (
                <Button type="button" variant="secondary" size="sm" onClick={() => setAdderOpen(true)}>
                    + Ajouter un article
                </Button>
            )}
            {rowError[0] && <p className="text-xs text-danger">{rowError[0]}</p>}
        </div>
    );
}

function omit<T extends Record<number, unknown>>(src: T, key: number): T {
    const next = { ...src };
    delete next[key];
    return next;
}

function ArticleAdder({
    searchUrl,
    taxRates,
    currency,
    canApplyDiscount,
    adding,
    onAdd,
    onClose,
}: {
    searchUrl: string;
    taxRates: TaxRate[];
    currency: string;
    canApplyDiscount: boolean;
    adding: boolean;
    onAdd: (body: Record<string, string | number | null>, keepOpen?: boolean) => Promise<unknown>;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [rows, setRows] = useState<SearchRow[]>([]);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);
    const [manual, setManual] = useState(false);
    const [mForm, setMForm] = useState({
        name: '',
        reference: '',
        unit_label: '',
        tax_rate_id: taxRates[0]?.id.toString() ?? '',
        price_mode: 'ht',
        unit_price: '',
        quantity: '1',
        discount_value: '',
        discount_unit: '%',
    });
    const inputRef = useRef<HTMLInputElement>(null);
    const reqId = useRef(0);

    useEffect(() => {
        const ctrl = new AbortController();
        const id = ++reqId.current;
        const h = window.setTimeout(async () => {
            setLoading(true);
            try {
                const url = new URL(searchUrl, window.location.origin);
                if (query.trim() !== '') url.searchParams.set('search', query.trim());
                const res = await fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: ctrl.signal });
                if (res.ok && id === reqId.current) {
                    setRows(((await res.json()) as { data: SearchRow[] }).data);
                    setActive(0);
                }
            } catch {
                /* aborted */
            } finally {
                if (id === reqId.current) setLoading(false);
            }
        }, 250);
        return () => {
            ctrl.abort();
            window.clearTimeout(h);
        };
    }, [query, searchUrl]);

    const pickCatalog = (row: SearchRow) => {
        void onAdd({ line_type: 'catalog', product_variant_id: row.id, quantity: '1', discount_type: 'none', discount_value: '0' }, true).then(() => {
            setQuery('');
            setRows([]);
            inputRef.current?.focus();
        });
    };

    const openManual = () => {
        setManual(true);
        setMForm((f) => ({ ...f, name: query.trim() }));
    };

    const submitManual = () => {
        const hasDisc = canApplyDiscount && mForm.discount_value.trim() !== '' && Number(mForm.discount_value) > 0;
        void onAdd({
            line_type: 'custom',
            description: mForm.name.trim(),
            reference: mForm.reference.trim() || null,
            unit_label: mForm.unit_label.trim() || null,
            tax_rate_id: mForm.tax_rate_id ? Number(mForm.tax_rate_id) : null,
            price_input_mode: mForm.price_mode,
            unit_price: mForm.unit_price.trim() || '0',
            quantity: mForm.quantity.trim() || '1',
            discount_type: hasDisc ? (mForm.discount_unit === 'DH' ? 'fixed' : 'percentage') : 'none',
            discount_value: hasDisc ? mForm.discount_value.trim() : '0',
        });
    };

    const onKeyDown = (e: ReactKeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => Math.min(a + 1, rows.length));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(a - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (active < rows.length) pickCatalog(rows[active]);
            else openManual();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            onClose();
        }
    };

    return (
        <div className="rounded-card border border-line bg-surface p-3 text-sm">
            {!manual ? (
                <>
                    <div className="flex items-center gap-2">
                        <input
                            ref={inputRef}
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder="Nom, SKU, référence, code-barres, marque…"
                            className="w-full rounded-field border border-line-strong px-2 py-1"
                        />
                        <button type="button" onClick={onClose} className="text-xs text-ink-muted hover:underline">
                            Fermer
                        </button>
                    </div>
                    <ul className="mt-2 max-h-64 divide-y divide-line overflow-y-auto">
                        {loading && <li className="px-1 py-2 text-xs text-ink-muted">Recherche…</li>}
                        {!loading &&
                            rows.map((row, index) => {
                                const outOfStock = Number(row.stock_available) <= 0;
                                return (
                                    <li key={row.id}>
                                        <button
                                            type="button"
                                            disabled={adding}
                                            onMouseEnter={() => setActive(index)}
                                            onClick={() => pickCatalog(row)}
                                            className={`flex w-full items-start justify-between gap-3 px-1 py-2 text-left disabled:opacity-50 ${
                                                index === active ? 'bg-raised' : 'hover:bg-raised'
                                            }`}
                                        >
                                            <span>
                                                <span className="mr-1 rounded bg-raised px-1 text-[10px] uppercase tracking-wide text-ink-faint">Catalogue</span>
                                                <span className="font-medium text-ink">{row.product_name}</span>
                                                {row.variant_name && <span className="block text-xs text-ink-muted">{row.variant_name}</span>}
                                                <span className="block text-xs text-ink-faint">
                                                    {row.reference ?? row.sku ?? '—'}
                                                    {' · '}
                                                    {outOfStock ? 'Hors stock' : `Stock société : ${formatQuantity(row.stock_available)}`}
                                                </span>
                                            </span>
                                            <span className="whitespace-nowrap text-xs text-ink-muted">
                                                {row.unit_price_excl_tax
                                                    ? `${formatMoney(row.unit_price_excl_tax, currency)} HT`
                                                    : row.unit_price_incl_tax
                                                      ? `${formatMoney(row.unit_price_incl_tax, currency)} TTC`
                                                      : '—'}
                                                <span className={`block ${row.tax_unresolved ? 'text-warning' : 'text-ink-faint'}`}>
                                                    {row.tax_unresolved ? 'TVA à définir' : `TVA ${formatQuantity(row.tax_rate)} %`}
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        {!loading && (
                            <li>
                                <button
                                    type="button"
                                    onMouseEnter={() => setActive(rows.length)}
                                    onClick={openManual}
                                    className={`block w-full px-1 py-2 text-left text-primary ${rows.length === active ? 'bg-raised' : 'hover:bg-raised'}`}
                                >
                                    + Ajouter{query.trim() ? ` « ${query.trim()} »` : ''} comme article personnalisé
                                </button>
                            </li>
                        )}
                    </ul>
                </>
            ) : (
                <div className="space-y-2">
                    <p className="text-sm font-medium text-ink">Nouvel article personnalisé</p>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <input
                            placeholder="Désignation *"
                            value={mForm.name}
                            onChange={(e) => setMForm((f) => ({ ...f, name: e.target.value }))}
                            className="rounded-field border border-line-strong px-2 py-1 sm:col-span-2"
                        />
                        <input placeholder="Référence" value={mForm.reference} onChange={(e) => setMForm((f) => ({ ...f, reference: e.target.value }))} className="rounded-field border border-line-strong px-2 py-1" />
                        <input placeholder="Unité" value={mForm.unit_label} onChange={(e) => setMForm((f) => ({ ...f, unit_label: e.target.value }))} className="rounded-field border border-line-strong px-2 py-1" />
                        <label className="text-xs text-ink-muted">
                            Quantité
                            <input type="number" inputMode="numeric" min={1} step={1} value={mForm.quantity} onChange={(e) => setMForm((f) => ({ ...f, quantity: e.target.value }))} className="mt-0.5 w-full rounded-field border border-line-strong px-2 py-1 text-right" />
                        </label>
                        <label className="text-xs text-ink-muted">
                            TVA
                            <select value={mForm.tax_rate_id} onChange={(e) => setMForm((f) => ({ ...f, tax_rate_id: e.target.value }))} className="mt-0.5 w-full rounded-field border border-line-strong px-2 py-1">
                                <option value="">Aucune</option>
                                {taxRates.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name} ({formatQuantity(t.rate)} %)
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-xs text-ink-muted">
                            Prix
                            <div className="mt-0.5 flex gap-1">
                                <input
                                    inputMode="decimal"
                                    value={mForm.unit_price}
                                    onChange={(e) => setMForm((f) => ({ ...f, unit_price: e.target.value }))}
                                    className="w-full rounded-field border border-line-strong px-2 py-1 text-right"
                                />
                                <select value={mForm.price_mode} onChange={(e) => setMForm((f) => ({ ...f, price_mode: e.target.value }))} className="rounded-field border border-line-strong px-1 py-1 text-xs">
                                    <option value="ht">HT</option>
                                    <option value="ttc">TTC</option>
                                </select>
                            </div>
                        </label>
                        {canApplyDiscount && (
                            <label className="text-xs text-ink-muted">
                                Remise
                                <div className="mt-0.5 flex gap-1">
                                    <input
                                        inputMode="decimal"
                                        placeholder="0"
                                        value={mForm.discount_value}
                                        onChange={(e) => setMForm((f) => ({ ...f, discount_value: e.target.value }))}
                                        className="w-full rounded-field border border-line-strong px-2 py-1 text-right"
                                    />
                                    <select value={mForm.discount_unit} onChange={(e) => setMForm((f) => ({ ...f, discount_unit: e.target.value }))} className="rounded-field border border-line-strong px-1 py-1 text-xs">
                                        <option value="%">%</option>
                                        <option value="DH">DH</option>
                                    </select>
                                </div>
                            </label>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" size="sm" loading={adding} loadingText="Ajout…" disabled={mForm.name.trim() === ''} onClick={submitManual}>
                            Ajouter à la commande
                        </Button>
                        <button type="button" onClick={() => setManual(false)} className="text-xs text-ink-muted hover:underline">
                            Retour à la recherche
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
