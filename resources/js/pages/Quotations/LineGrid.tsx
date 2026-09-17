import { Button } from '@/components/ui/Button';
import { usePendingKeys } from '@/hooks/usePendingKeys';
import { useSerializedByKey } from '@/hooks/useSerializedByKey';
import { formatMoney, formatQuantity } from '@/utils/format';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export type QLine = {
    id: number;
    line_type: 'catalog' | 'non_stock';
    product_variant_id: number | null;
    non_stock_item_id: number | null;
    description: string;
    product_name: string | null;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    unit_label: string | null;
    price_input_mode: 'ht' | 'ttc';
    quantity: string;
    unit_price_excl_tax: string;
    unit_price_incl_tax: string;
    tax_name: string | null;
    tax_rate: string;
    discount_type: 'none' | 'fixed' | 'percentage';
    discount_value: string;
    discount_amount: string;
    taxable_amount: string;
    tax_amount: string;
    total_incl_tax: string;
};
type TaxRate = { id: number; name: string; rate: string; is_default?: boolean };
type SearchRow = {
    kind: 'catalog' | 'non_stock';
    id: number;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    unit_label: string | null;
    unit_price_excl_tax: string | null;
    unit_price_incl_tax: string | null;
    tax_rate: string;
    tax_name: string | null;
    tax_config_missing?: boolean;
    stock_available?: string;
    usage_count?: number;
    price_input_mode?: 'ht' | 'ttc';
};
type Props = {
    quotationId: number;
    currency: string;
    lines: QLine[];
    searchUrl: string;
    taxRates: TaxRate[];
};
type Draft = { quantity: string; price_input_mode: 'ht' | 'ttc'; unit_price: string; discount_value: string; discount_unit: '%' | 'DH' };

const RELOAD_ONLY = ['quotation', 'hasDiscount', 'errors', 'flash'];

function draftFromLine(l: QLine): Draft {
    return {
        quantity: l.quantity,
        price_input_mode: l.price_input_mode,
        unit_price: l.price_input_mode === 'ttc' ? l.unit_price_incl_tax : l.unit_price_excl_tax,
        discount_value: l.discount_type === 'none' ? '' : l.discount_value,
        discount_unit: l.discount_type === 'fixed' ? 'DH' : '%',
    };
}

export default function LineGrid({ quotationId, currency, lines, searchUrl, taxRates }: Props) {
    const pending = usePendingKeys();
    const serialized = useSerializedByKey();
    const timers = useRef<Record<number, number>>({});
    const draftsRef = useRef<Record<number, Draft>>({});
    const [drafts, setDrafts] = useState<Record<number, Draft>>(() => Object.fromEntries(lines.map((l) => [l.id, draftFromLine(l)])));
    const [rowError, setRowError] = useState<Record<number, string>>({});
    const [adderOpen, setAdderOpen] = useState(false);
    draftsRef.current = drafts;

    useEffect(() => {
        setDrafts((cur) => {
            const next: Record<number, Draft> = {};
            for (const l of lines) {
                next[l.id] = serialized.isBusy(`line:${l.id}`) ? (cur[l.id] ?? draftFromLine(l)) : draftFromLine(l);
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
        const hasDisc = d.discount_value.trim() !== '' && Number(d.discount_value) > 0;
        const body: Record<string, string | number | null> = {
            line_type: line.line_type,
            quantity: d.quantity.trim() === '' ? '0' : d.quantity.trim(),
            price_input_mode: d.price_input_mode,
            unit_price: d.unit_price.trim() === '' ? null : d.unit_price.trim(),
            discount_type: hasDisc ? (d.discount_unit === 'DH' ? 'fixed' : 'percentage') : 'none',
            discount_value: hasDisc ? d.discount_value.trim() : '0',
        };
        if (line.line_type === 'catalog') body.product_variant_id = line.product_variant_id;
        else body.non_stock_item_id = line.non_stock_item_id;

        pending.start(`line:${id}`);
        return new Promise((resolve) => {
            router.patch(`/quotations/${quotationId}/lines/${id}`, body, {
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
                router.delete(`/quotations/${quotationId}/lines/${id}`, {
                    preserveScroll: true,
                    preserveState: true,
                    only: RELOAD_ONLY,
                    onError: (e) => setRowError((c) => ({ ...c, [id]: Object.values(e)[0] ?? 'Suppression refusée.' })),
                    onFinish: () => resolve(),
                });
            }),
        );
    }

    function addArticle(body: Record<string, string | number | null>) {
        return pending.run('line:add', () =>
            new Promise<void>((resolve) => {
                router.post(`/quotations/${quotationId}/lines`, body, {
                    preserveScroll: true,
                    preserveState: true,
                    only: RELOAD_ONLY,
                    onError: (e) => setRowError((c) => ({ ...c, 0: Object.values(e)[0] ?? 'Ajout refusé.' })),
                    onSuccess: () => {
                        setRowError((c) => omit(c, 0));
                        setAdderOpen(false);
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
                            <th className="w-40">PU</th>
                            <th className="w-32">Remise</th>
                            <th className="w-20 text-right">TVA</th>
                            <th className="w-28 text-right">Total HT</th>
                            <th className="w-32 text-right">Total TTC</th>
                            <th className="w-8" />
                        </tr>
                    </thead>
                    <tbody className="[&>tr>td]:border-b [&>tr>td]:border-line [&>tr>td]:px-2 [&>tr>td]:py-2 [&>tr>td]:align-top">
                        {lines.map((line) => {
                            const d = drafts[line.id] ?? draftFromLine(line);
                            const busy = pending.isPending(`line:${line.id}`) || pending.isPending(`rm:${line.id}`);
                            return (
                                <tr key={line.id} className={busy ? 'opacity-60' : undefined}>
                                    <td>
                                        <span className="font-medium text-ink">{line.product_name ?? line.description}</span>
                                        {line.variant_name && <span className="block text-xs text-ink-muted">{line.variant_name}</span>}
                                        <span className="block text-xs text-ink-faint">
                                            {line.line_type === 'non_stock' ? 'Hors stock' : 'Catalogue'}
                                            {line.reference ? ` · ${line.reference}` : line.sku ? ` · ${line.sku}` : ''}
                                            {line.unit_label ? ` · ${line.unit_label}` : ''}
                                        </span>
                                        {rowError[line.id] && <p className="mt-1 text-xs text-danger">{rowError[line.id]}</p>}
                                    </td>
                                    <td className="text-right">
                                        <input
                                            inputMode="decimal"
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
                                            <select
                                                disabled={busy}
                                                value={d.price_input_mode}
                                                onChange={(e) => {
                                                    patchDraft(line.id, { price_input_mode: e.target.value as 'ht' | 'ttc' });
                                                    commit(line.id);
                                                }}
                                                className="rounded-field border border-line-strong px-1 py-1 text-xs"
                                            >
                                                <option value="ht">HT</option>
                                                <option value="ttc">TTC</option>
                                            </select>
                                        </div>
                                        <span className="mt-0.5 block text-right text-xs text-ink-faint">
                                            {d.price_input_mode === 'ht'
                                                ? `TTC ${formatMoney(line.unit_price_incl_tax, currency)}`
                                                : `HT ${formatMoney(line.unit_price_excl_tax, currency)}`}
                                        </span>
                                    </td>
                                    <td>
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
                                    </td>
                                    <td className="text-right text-xs tabular-nums text-ink-muted">
                                        {formatQuantity(line.tax_rate)} %
                                        {line.tax_name && <span className="block text-ink-faint">{line.tax_name}</span>}
                                    </td>
                                    <td className="text-right tabular-nums text-ink-muted">{formatMoney(line.taxable_amount, currency)}</td>
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
                                <td colSpan={8} className="px-2 py-6 text-center text-ink-muted">
                                    Aucune ligne. Ajoutez un article ci-dessous.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* Mobile: stacked editable line cards */}
            <ul className="space-y-3 md:hidden">
                {lines.map((line) => {
                    const d = drafts[line.id] ?? draftFromLine(line);
                    const busy = pending.isPending(`line:${line.id}`) || pending.isPending(`rm:${line.id}`);
                    return (
                        <li key={line.id} className={`rounded-card border border-line bg-surface p-3 ${busy ? 'opacity-60' : ''}`}>
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <span className="mr-1 rounded bg-raised px-1 text-[10px] uppercase tracking-wide text-ink-faint">
                                        {line.line_type === 'non_stock' ? 'Hors stock' : 'Catalogue'}
                                    </span>
                                    <p className="truncate font-medium text-ink">{line.product_name ?? line.description}</p>
                                    {line.variant_name && <p className="truncate text-xs text-ink-muted">{line.variant_name}</p>}
                                    <p className="truncate text-xs text-ink-faint">
                                        {[line.reference ?? line.sku, line.unit_label].filter(Boolean).join(' · ')}
                                    </p>
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
                                        inputMode="decimal"
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
                                    <p className="mt-1.5 text-sm text-ink">
                                        {formatQuantity(line.tax_rate)} % {line.tax_name && <span className="block text-xs text-ink-faint">{line.tax_name}</span>}
                                    </p>
                                </label>

                                <label className="col-span-2 block">
                                    Prix unitaire
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
                                        <select
                                            disabled={busy}
                                            value={d.price_input_mode}
                                            onChange={(e) => {
                                                patchDraft(line.id, { price_input_mode: e.target.value as 'ht' | 'ttc' });
                                                commit(line.id);
                                            }}
                                            className="h-9 shrink-0 rounded-field border border-line-strong px-1.5 text-xs text-ink"
                                        >
                                            <option value="ht">HT</option>
                                            <option value="ttc">TTC</option>
                                        </select>
                                    </div>
                                    <span className="mt-1 block text-right text-xs text-ink-faint">
                                        {d.price_input_mode === 'ht'
                                            ? `TTC ${formatMoney(line.unit_price_incl_tax, currency)}`
                                            : `HT ${formatMoney(line.unit_price_excl_tax, currency)}`}
                                    </span>
                                </label>

                                <label className="col-span-2 block">
                                    Remise
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
                                </label>
                            </div>

                            <div className="mt-3 flex items-center justify-between border-t border-line pt-2.5 text-sm">
                                <span className="text-ink-muted">Total HT <span className="tabular-nums">{formatMoney(line.taxable_amount, currency)}</span></span>
                                <span className="font-semibold text-ink">Total TTC <span className="tabular-nums">{formatMoney(line.total_incl_tax, currency)}</span></span>
                            </div>
                        </li>
                    );
                })}
                {lines.length === 0 && (
                    <li className="rounded-card border border-dashed border-line-strong px-3 py-8 text-center text-sm text-ink-muted">
                        Aucune ligne. Ajoutez un article ci-dessous.
                    </li>
                )}
            </ul>

            {adderOpen ? (
                <ArticleAdder
                    searchUrl={searchUrl}
                    taxRates={taxRates}
                    currency={currency}
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
    adding,
    onAdd,
    onClose,
}: {
    searchUrl: string;
    taxRates: TaxRate[];
    currency: string;
    adding: boolean;
    onAdd: (body: Record<string, string | number | null>) => Promise<unknown>;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [rows, setRows] = useState<SearchRow[]>([]);
    const [loading, setLoading] = useState(false);
    const [manual, setManual] = useState<null | { name: string }>(null);
    const [mForm, setMForm] = useState({ name: '', reference: '', unit_label: '', tax_rate_id: '', price_input_mode: 'ht', unit_price: '' });

    useEffect(() => {
        const ctrl = new AbortController();
        const h = window.setTimeout(async () => {
            setLoading(true);
            try {
                const url = new URL(searchUrl, window.location.origin);
                if (query.trim() !== '') url.searchParams.set('search', query.trim());
                const res = await fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: ctrl.signal });
                if (res.ok) setRows(((await res.json()) as { data: SearchRow[] }).data);
            } catch {
                /* aborted */
            } finally {
                setLoading(false);
            }
        }, 300);
        return () => {
            ctrl.abort();
            window.clearTimeout(h);
        };
    }, [query, searchUrl]);

    const pick = (row: SearchRow) => {
        if (row.kind === 'catalog') {
            void onAdd({ line_type: 'catalog', product_variant_id: row.id, quantity: '1', discount_type: 'none', discount_value: '0' });
        } else {
            void onAdd({ line_type: 'non_stock', non_stock_item_id: row.id, quantity: '1', discount_type: 'none', discount_value: '0' });
        }
    };

    const submitManual = () => {
        void onAdd({
            line_type: 'non_stock',
            name: mForm.name.trim(),
            reference: mForm.reference.trim() || null,
            unit_label: mForm.unit_label.trim() || null,
            tax_rate_id: mForm.tax_rate_id ? Number(mForm.tax_rate_id) : null,
            price_input_mode: mForm.price_input_mode,
            unit_price: mForm.unit_price.trim() || null,
            quantity: '1',
            discount_type: 'none',
            discount_value: '0',
        });
    };

    return (
        <div className="rounded-card border border-line bg-surface p-3 text-sm">
            {manual === null ? (
                <>
                    <div className="flex items-center gap-2">
                        <input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
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
                            rows.map((row) => (
                                <li key={`${row.kind}:${row.id}`}>
                                    <button
                                        type="button"
                                        disabled={adding || row.tax_config_missing}
                                        onClick={() => pick(row)}
                                        className="flex w-full items-start justify-between gap-3 px-1 py-2 text-left hover:bg-raised disabled:opacity-50"
                                    >
                                        <span>
                                            <span className="mr-1 rounded bg-raised px-1 text-[10px] uppercase tracking-wide text-ink-faint">
                                                {row.kind === 'catalog' ? 'Catalogue' : 'Hors stock'}
                                            </span>
                                            <span className="font-medium text-ink">{row.product_name}</span>
                                            {row.variant_name && <span className="block text-xs text-ink-muted">{row.variant_name}</span>}
                                            <span className="block text-xs text-ink-faint">
                                                {row.reference ?? row.sku ?? '—'}
                                                {row.kind === 'catalog' && row.stock_available !== undefined
                                                    ? ` · Stock société : ${formatQuantity(row.stock_available)}`
                                                    : row.kind === 'non_stock'
                                                      ? ' · Article externe'
                                                      : ''}
                                            </span>
                                        </span>
                                        <span className="whitespace-nowrap text-xs text-ink-muted">
                                            {row.unit_price_excl_tax ? `${formatMoney(row.unit_price_excl_tax, currency)} HT` : '—'}
                                            <span className="block text-ink-faint">{formatQuantity(row.tax_rate)} %</span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        {!loading && (
                            <li>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setManual({ name: query });
                                        setMForm((f) => ({ ...f, name: query }));
                                    }}
                                    className="block w-full px-1 py-2 text-left text-primary hover:bg-raised"
                                >
                                    + Ajouter un article hors stock{query.trim() ? ` « ${query.trim()} »` : ''}
                                </button>
                            </li>
                        )}
                    </ul>
                </>
            ) : (
                <div className="space-y-2">
                    <p className="text-sm font-medium text-ink">Nouvel article hors stock</p>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <input
                            placeholder="Désignation *"
                            value={mForm.name}
                            onChange={(e) => setMForm((f) => ({ ...f, name: e.target.value }))}
                            className="rounded-field border border-line-strong px-2 py-1 sm:col-span-2"
                        />
                        <input placeholder="Référence" value={mForm.reference} onChange={(e) => setMForm((f) => ({ ...f, reference: e.target.value }))} className="rounded-field border border-line-strong px-2 py-1" />
                        <input placeholder="Unité" value={mForm.unit_label} onChange={(e) => setMForm((f) => ({ ...f, unit_label: e.target.value }))} className="rounded-field border border-line-strong px-2 py-1" />
                        <select value={mForm.tax_rate_id} onChange={(e) => setMForm((f) => ({ ...f, tax_rate_id: e.target.value }))} className="rounded-field border border-line-strong px-2 py-1">
                            <option value="">TVA : aucune</option>
                            {taxRates.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name} ({formatQuantity(t.rate)} %)
                                </option>
                            ))}
                        </select>
                        <div className="flex gap-1">
                            <input
                                placeholder="Prix"
                                inputMode="decimal"
                                value={mForm.unit_price}
                                onChange={(e) => setMForm((f) => ({ ...f, unit_price: e.target.value }))}
                                className="w-full rounded-field border border-line-strong px-2 py-1 text-right"
                            />
                            <select value={mForm.price_input_mode} onChange={(e) => setMForm((f) => ({ ...f, price_input_mode: e.target.value }))} className="rounded-field border border-line-strong px-1 py-1 text-xs">
                                <option value="ht">HT</option>
                                <option value="ttc">TTC</option>
                            </select>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" size="sm" loading={adding} loadingText="Ajout…" disabled={mForm.name.trim() === ''} onClick={submitManual}>
                            Ajouter au devis
                        </Button>
                        <button type="button" onClick={() => setManual(null)} className="text-xs text-ink-muted hover:underline">
                            Retour à la recherche
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
