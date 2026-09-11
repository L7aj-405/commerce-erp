import { Button } from '@/components/ui/Button';
import { usePendingKeys } from '@/hooks/usePendingKeys';
import { useSerializedByKey } from '@/hooks/useSerializedByKey';
import { formatMoney, formatQuantity } from '@/utils/format';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export type CorrectionLine = {
    id: number;
    product_variant_id: number | null;
    description: string;
    product_name: string | null;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    unit_label: string | null;
    quantity: string;
    unit_price_excl_tax: string;
    discount_type: 'none' | 'fixed' | 'percentage';
    discount_value: string;
    discount_amount: string;
    taxable_amount: string;
    tax_name: string | null;
    tax_rate: string;
    tax_amount: string;
    total_incl_tax: string;
};

type SearchResult = {
    id: number;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    barcode: string | null;
    unit_label: string | null;
    brand: { id: number; name: string } | null;
    unit_price_excl_tax: string;
    tax_rate: string;
    tax_name: string | null;
    tax_config_missing: boolean;
};

type Draft = {
    quantity: string;
    unit_price_excl_tax: string;
    discount_value: string;
    discount_unit: '%' | 'DH';
    replacement_variant_id?: number;
};

type Props = {
    invoiceId: number;
    currency: string;
    lines: CorrectionLine[];
    searchUrl: string;
};

const RELOAD_ONLY = ['invoice', 'correctionComparison', 'hasDiscount', 'relatedOrderPaymentSummary', 'errors', 'flash'];

function draftFromLine(line: CorrectionLine): Draft {
    return {
        quantity: line.quantity,
        unit_price_excl_tax: line.unit_price_excl_tax,
        discount_value: line.discount_type === 'none' ? '' : line.discount_value,
        discount_unit: line.discount_type === 'fixed' ? 'DH' : '%',
    };
}

function firstError(errors: Record<string, string>): string {
    return Object.values(errors)[0] ?? 'Modification refusée.';
}

export default function CorrectionLineEditor({ invoiceId, currency, lines, searchUrl }: Props) {
    const pending = usePendingKeys();
    const serialized = useSerializedByKey();
    const timers = useRef<Record<number, number>>({});
    const draftsRef = useRef<Record<number, Draft>>({});

    const [drafts, setDrafts] = useState<Record<number, Draft>>(() =>
        Object.fromEntries(lines.map((line) => [line.id, draftFromLine(line)])),
    );
    const [rowErrors, setRowErrors] = useState<Record<number, string>>({});
    const [confirmRemove, setConfirmRemove] = useState<number | null>(null);
    const [picker, setPicker] = useState<'add' | number | null>(null);

    draftsRef.current = drafts;

    // Re-sync each row from the server-authoritative props after a mutation,
    // except rows whose write is still in flight.
    useEffect(() => {
        setDrafts((current) => {
            const next: Record<number, Draft> = {};
            for (const line of lines) {
                next[line.id] = serialized.isBusy(`line:update:${line.id}`)
                    ? (current[line.id] ?? draftFromLine(line))
                    : draftFromLine(line);
            }
            return next;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lines]);

    function patchDraft(lineId: number, patch: Partial<Draft>) {
        setDrafts((current) => ({ ...current, [lineId]: { ...current[lineId], ...patch } }));
    }

    function scheduleCommit(lineId: number) {
        window.clearTimeout(timers.current[lineId]);
        timers.current[lineId] = window.setTimeout(() => commitLine(lineId), 500);
    }

    function commitLine(lineId: number) {
        window.clearTimeout(timers.current[lineId]);
        serialized.enqueue(`line:update:${lineId}`, () => submitLine(lineId));
    }

    function submitLine(lineId: number): Promise<unknown> {
        const draft = draftsRef.current[lineId];
        if (!draft) return Promise.resolve();

        const hasDiscount = draft.discount_value.trim() !== '' && Number(draft.discount_value) > 0;
        const body: Record<string, string> = {
            quantity: draft.quantity.trim() === '' ? '0' : draft.quantity.trim(),
            discount_type: hasDiscount ? (draft.discount_unit === 'DH' ? 'fixed' : 'percentage') : 'none',
            discount_value: hasDiscount ? draft.discount_value.trim() : '0',
        };
        if (draft.replacement_variant_id != null) {
            body.product_variant_id = String(draft.replacement_variant_id);
            // Let the server suggest the new Product's price unless the employee
            // has already overridden it in the same edit.
            if (draft.unit_price_excl_tax.trim() !== '') body.unit_price_excl_tax = draft.unit_price_excl_tax.trim();
        } else if (draft.unit_price_excl_tax.trim() !== '') {
            body.unit_price_excl_tax = draft.unit_price_excl_tax.trim();
        }

        const key = `line:update:${lineId}`;
        pending.start(key);
        return new Promise((resolve) => {
            router.patch(`/invoices/${invoiceId}/correction-lines/${lineId}`, body, {
                preserveScroll: true,
                preserveState: true,
                only: RELOAD_ONLY,
                onError: (errors) => setRowErrors((c) => ({ ...c, [lineId]: firstError(errors) })),
                onSuccess: () => setRowErrors((c) => omit(c, lineId)),
                onFinish: () => {
                    pending.stop(key);
                    resolve(undefined);
                },
            });
        });
    }

    function addProduct(result: SearchResult) {
        if (result.tax_config_missing) {
            setRowErrors((c) => ({ ...c, 0: 'Aucune taxe configurée pour ce produit.' }));
            return;
        }
        const key = 'line:add';
        void pending.run(key, () =>
            new Promise<void>((resolve) => {
                router.post(
                    `/invoices/${invoiceId}/correction-lines`,
                    { product_variant_id: String(result.id), quantity: '1', discount_type: 'none', discount_value: '0' },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        only: RELOAD_ONLY,
                        onError: (errors) => setRowErrors((c) => ({ ...c, 0: firstError(errors) })),
                        onSuccess: () => {
                            setRowErrors((c) => omit(c, 0));
                            setPicker(null);
                        },
                        onFinish: () => resolve(),
                    },
                );
            }),
        );
    }

    function replaceProduct(lineId: number, result: SearchResult) {
        if (result.tax_config_missing) {
            setRowErrors((c) => ({ ...c, [lineId]: 'Aucune taxe configurée pour ce produit.' }));
            return;
        }
        patchDraft(lineId, { replacement_variant_id: result.id, unit_price_excl_tax: '' });
        setPicker(null);
        commitLine(lineId);
    }

    function removeLine(lineId: number) {
        const key = `line:remove:${lineId}`;
        void pending.run(key, () =>
            new Promise<void>((resolve) => {
                router.delete(`/invoices/${invoiceId}/correction-lines/${lineId}`, {
                    preserveScroll: true,
                    preserveState: true,
                    only: RELOAD_ONLY,
                    onError: (errors) => setRowErrors((c) => ({ ...c, [lineId]: firstError(errors) })),
                    onSuccess: () => setRowErrors((c) => omit(c, lineId)),
                    onFinish: () => {
                        setConfirmRemove(null);
                        resolve();
                    },
                });
            }),
        );
    }

    const addPending = pending.isPending('line:add');

    return (
        <div className="space-y-3">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] border-collapse text-sm">
                    <thead>
                        <tr className="border-b border-line-strong text-left text-xs uppercase tracking-wide text-ink-muted [&>th]:px-2 [&>th]:py-2 [&>th]:font-medium">
                            <th>Produit</th>
                            <th className="w-20 text-right">Qté</th>
                            <th className="w-28 text-right">PU HT</th>
                            <th className="w-40">Remise</th>
                            <th className="w-24 text-right">TVA</th>
                            <th className="w-32 text-right">Total TTC</th>
                            <th className="w-10" />
                        </tr>
                    </thead>
                    <tbody className="[&>tr>td]:border-b [&>tr>td]:border-line [&>tr>td]:px-2 [&>tr>td]:py-2 [&>tr>td]:align-top">
                        {lines.map((line) => {
                            const draft = drafts[line.id] ?? draftFromLine(line);
                            const busy = pending.isPending(`line:update:${line.id}`);
                            const removing = pending.isPending(`line:remove:${line.id}`);
                            const disabled = busy || removing;

                            return (
                                <tr key={line.id} className={disabled ? 'opacity-60' : undefined}>
                                    <td>
                                        <span className="font-medium text-ink">{line.product_name ?? line.description}</span>
                                        {line.variant_name && (
                                            <span className="block text-xs text-ink-muted">{line.variant_name}</span>
                                        )}
                                        <span className="block text-xs text-ink-faint">
                                            {line.reference ?? line.sku ?? '—'}
                                            {line.unit_label ? ` · ${line.unit_label}` : ''}
                                        </span>
                                        <button
                                            type="button"
                                            disabled={disabled}
                                            onClick={() => setPicker(picker === line.id ? null : line.id)}
                                            className="mt-1 text-xs text-ink-muted underline hover:text-ink disabled:no-underline"
                                        >
                                            Changer le produit
                                        </button>
                                        {picker === line.id && (
                                            <ProductSearch
                                                searchUrl={searchUrl}
                                                onPick={(result) => replaceProduct(line.id, result)}
                                                onClose={() => setPicker(null)}
                                            />
                                        )}
                                        {rowErrors[line.id] && (
                                            <p className="mt-1 text-xs text-danger">{rowErrors[line.id]}</p>
                                        )}
                                    </td>
                                    <td className="text-right">
                                        <input
                                            inputMode="decimal"
                                            disabled={disabled}
                                            value={draft.quantity}
                                            onChange={(e) => {
                                                patchDraft(line.id, { quantity: e.target.value });
                                                scheduleCommit(line.id);
                                            }}
                                            onBlur={() => commitLine(line.id)}
                                            className="w-16 rounded-field border border-line-strong px-2 py-1 text-right tabular-nums"
                                        />
                                    </td>
                                    <td className="text-right">
                                        <input
                                            inputMode="decimal"
                                            disabled={disabled}
                                            value={draft.unit_price_excl_tax}
                                            onChange={(e) => {
                                                patchDraft(line.id, { unit_price_excl_tax: e.target.value });
                                                scheduleCommit(line.id);
                                            }}
                                            onBlur={() => commitLine(line.id)}
                                            className="w-24 rounded-field border border-line-strong px-2 py-1 text-right tabular-nums"
                                        />
                                    </td>
                                    <td>
                                        <div className="flex items-center gap-1">
                                            <input
                                                inputMode="decimal"
                                                disabled={disabled}
                                                placeholder="0"
                                                value={draft.discount_value}
                                                onChange={(e) => {
                                                    patchDraft(line.id, { discount_value: e.target.value });
                                                    scheduleCommit(line.id);
                                                }}
                                                onBlur={() => commitLine(line.id)}
                                                className="w-16 rounded-field border border-line-strong px-2 py-1 text-right tabular-nums"
                                            />
                                            <select
                                                disabled={disabled}
                                                value={draft.discount_unit}
                                                onChange={(e) => {
                                                    patchDraft(line.id, { discount_unit: e.target.value as '%' | 'DH' });
                                                    commitLine(line.id);
                                                }}
                                                className="rounded-field border border-line-strong px-1 py-1 text-xs"
                                            >
                                                <option value="%">%</option>
                                                <option value="DH">DH</option>
                                            </select>
                                        </div>
                                        {Number(line.discount_amount) > 0 && (
                                            <span className="mt-0.5 block text-right text-xs text-ink-faint">
                                                - {formatMoney(line.discount_amount, currency)}
                                            </span>
                                        )}
                                    </td>
                                    <td className="text-right text-xs tabular-nums text-ink-muted">
                                        {formatQuantity(line.tax_rate)} %
                                        {line.tax_name && <span className="block text-ink-faint">{line.tax_name}</span>}
                                    </td>
                                    <td className="text-right font-medium tabular-nums text-ink">
                                        {formatMoney(line.total_incl_tax, currency)}
                                    </td>
                                    <td className="text-right">
                                        {confirmRemove === line.id ? (
                                            <span className="flex flex-col gap-1">
                                                <button
                                                    type="button"
                                                    disabled={removing}
                                                    onClick={() => removeLine(line.id)}
                                                    className="text-xs font-medium text-danger hover:underline"
                                                >
                                                    Retirer
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setConfirmRemove(null)}
                                                    className="text-xs text-ink-muted hover:underline"
                                                >
                                                    Non
                                                </button>
                                            </span>
                                        ) : (
                                            <button
                                                type="button"
                                                disabled={disabled || lines.length <= 1}
                                                title={lines.length <= 1 ? 'Au moins une ligne est requise' : 'Retirer la ligne'}
                                                onClick={() => setConfirmRemove(line.id)}
                                                className="text-ink-faint hover:text-danger disabled:opacity-40"
                                            >
                                                ✕
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div>
                {picker === 'add' ? (
                    <div className="rounded-card border border-line bg-surface p-3">
                        <ProductSearch searchUrl={searchUrl} onPick={addProduct} onClose={() => setPicker(null)} />
                    </div>
                ) : (
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        loading={addPending}
                        loadingText="Ajout…"
                        onClick={() => setPicker('add')}
                    >
                        + Ajouter un produit
                    </Button>
                )}
                {rowErrors[0] && <p className="mt-1 text-xs text-danger">{rowErrors[0]}</p>}
            </div>
        </div>
    );
}

function omit<T extends Record<number, unknown>>(source: T, key: number): T {
    const next = { ...source };
    delete next[key];
    return next;
}

function ProductSearch({
    searchUrl,
    onPick,
    onClose,
}: {
    searchUrl: string;
    onPick: (result: SearchResult) => void;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        const handle = window.setTimeout(async () => {
            setLoading(true);
            try {
                const url = new URL(searchUrl, window.location.origin);
                if (query.trim() !== '') url.searchParams.set('search', query.trim());
                const response = await fetch(url.toString(), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });
                if (response.ok) {
                    const body = (await response.json()) as { data: SearchResult[] };
                    setResults(body.data);
                }
            } catch {
                /* aborted or offline */
            } finally {
                setLoading(false);
            }
        }, 300);

        return () => {
            controller.abort();
            window.clearTimeout(handle);
        };
    }, [query, searchUrl]);

    return (
        <div className="mt-2 rounded-field border border-line-strong bg-surface p-2 text-sm shadow-sm">
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
            <ul className="mt-2 max-h-56 divide-y divide-line overflow-y-auto">
                {loading && <li className="px-1 py-2 text-xs text-ink-muted">Recherche…</li>}
                {!loading && results.length === 0 && (
                    <li className="px-1 py-2 text-xs text-ink-muted">Aucun produit trouvé.</li>
                )}
                {results.map((result) => (
                    <li key={result.id}>
                        <button
                            type="button"
                            onClick={() => onPick(result)}
                            className="flex w-full items-start justify-between gap-3 px-1 py-2 text-left hover:bg-raised"
                        >
                            <span>
                                <span className="font-medium text-ink">{result.product_name}</span>
                                {result.variant_name && (
                                    <span className="block text-xs text-ink-muted">{result.variant_name}</span>
                                )}
                                <span className="block text-xs text-ink-faint">
                                    {result.reference ?? result.sku ?? '—'}
                                    {result.brand ? ` · ${result.brand.name}` : ''}
                                </span>
                            </span>
                            <span className="whitespace-nowrap text-xs text-ink-muted">
                                {formatMoney(result.unit_price_excl_tax)} HT · {formatQuantity(result.tax_rate)} %
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
