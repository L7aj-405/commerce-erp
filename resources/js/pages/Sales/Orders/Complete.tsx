import { Button } from '@/components/ui/Button';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import SalesLayout from '@/layouts/SalesLayout';
import { formatMoney, formatQuantity } from '@/utils/format';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type ExistingLine = {
    id: number;
    product_name: string;
    variant_name: string | null;
    quantity: string;
    total_incl_tax: string;
    addendum: { sequence: number } | null;
};
type Variant = {
    id: number;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    unit_label: string | null;
    unit_price_incl_tax: string | null;
    tax_rate: string;
    config_missing: boolean;
    local_stock_available: string;
};
type CartLine = Variant & { quantity: string };
type Props = {
    order: {
        id: number;
        order_number: string;
        currency_code: string;
        total_incl_tax: string;
        store: { name: string };
        lines: ExistingLine[];
    };
    paymentSummary: { paid: string; remaining: string };
    searchUrl: string;
    submitUrl: string;
};

export default function CompletePosOrder({ order, paymentSummary, searchUrl, submitUrl }: Props) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Variant[]>([]);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState('');
    const [activeResult, setActiveResult] = useState(-1);
    const [retryToken, setRetryToken] = useState(0);
    const [cart, setCart] = useState<CartLine[]>([]);
    const requestId = useRef(0);
    const abortRef = useRef<AbortController | null>(null);
    const debouncedQuery = useDebouncedValue(query.trim(), 250);
    const form = useForm<{ client_operation_id: string; lines: { product_variant_id: number; quantity: string }[] }>({
        client_operation_id: crypto.randomUUID(),
        lines: [],
    });

    useEffect(() => {
        const id = ++requestId.current;
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        if (debouncedQuery === '') {
            setResults([]);
            setSearchError('');
            setSearching(false);
            setActiveResult(-1);
            return () => controller.abort();
        }

        setSearching(true);
        setSearchError('');
        setResults([]);
        setActiveResult(-1);
        void fetch(`${searchUrl}?search=${encodeURIComponent(debouncedQuery)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('search_failed');
                const payload = (await response.json()) as { data?: Variant[] };
                if (id !== requestId.current) return;
                const rows = payload.data ?? [];
                setResults(rows);
                const firstSelectable = rows.findIndex((row) => !row.config_missing && Number(row.local_stock_available) > 0 && !cart.some((line) => line.id === row.id));
                setActiveResult(firstSelectable);
            })
            .catch((error: unknown) => {
                if (controller.signal.aborted || id !== requestId.current) return;
                setSearchError(error instanceof Error ? 'Recherche indisponible. Réessayez.' : 'Recherche indisponible.');
            })
            .finally(() => {
                if (id === requestId.current) setSearching(false);
            });

        return () => controller.abort();
    }, [debouncedQuery, retryToken, searchUrl]);

    const isUnavailable = (variant: Variant) =>
        variant.config_missing || Number(variant.local_stock_available) <= 0 || cart.some((line) => line.id === variant.id);
    const changeSearch = (value: string) => {
        abortRef.current?.abort();
        requestId.current += 1;
        setQuery(value);
        setResults([]);
        setSearchError('');
        setActiveResult(-1);
        setSearching(value.trim() !== '');
    };
    const add = (variant: Variant) => {
        if (isUnavailable(variant)) return;
        abortRef.current?.abort();
        requestId.current += 1;
        setCart([...cart, { ...variant, quantity: '1' }]);
        setQuery('');
        setResults([]);
        setSearching(false);
        setActiveResult(-1);
    };
    const onSearchKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        const selectable = results.map((row, index) => ({ row, index })).filter(({ row }) => !isUnavailable(row));
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (selectable.length === 0) return;
            const current = selectable.findIndex(({ index }) => index === activeResult);
            const offset = event.key === 'ArrowDown' ? 1 : -1;
            const next = current < 0 ? (offset > 0 ? 0 : selectable.length - 1) : (current + offset + selectable.length) % selectable.length;
            setActiveResult(selectable[next].index);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (activeResult >= 0 && results[activeResult] && !isUnavailable(results[activeResult])) add(results[activeResult]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            abortRef.current?.abort();
            requestId.current += 1;
            setQuery('');
            setResults([]);
            setSearchError('');
            setSearching(false);
            setActiveResult(-1);
        }
    };
    const setQuantity = (id: number, quantity: string) =>
        setCart(cart.map((line) => (line.id === id ? { ...line, quantity } : line)));
    const addedTotal = cart.reduce((total, line) => total + Number(line.unit_price_incl_tax ?? 0) * Number(line.quantity || 0), 0);
    const newTotal = Number(order.total_incl_tax) + addedTotal;
    const newRemaining = Math.max(0, newTotal - Number(paymentSummary.paid));
    const submit = () => {
        const lines = cart.map((line) => ({ product_variant_id: line.id, quantity: line.quantity }));
        form.transform((data) => ({ ...data, lines }));
        form.post(submitUrl);
    };

    return (
        <SalesLayout>
            <Head title={`Compléter ${order.order_number}`} />
            <div className="mx-auto max-w-5xl space-y-6">
                <header>
                    <Link href={`/sales/orders/${order.id}`} className="text-sm text-ink-muted">← {order.order_number}</Link>
                    <h1 className="mt-1 text-2xl font-semibold text-ink">Compléter la commande</h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        Ajout uniquement · retrait immédiat depuis {order.store.name}. Les lignes déjà remises ne seront jamais modifiées.
                    </p>
                </header>

                <section className="rounded-card border border-line bg-surface p-5">
                    <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Articles existants — lecture seule</h2>
                    <ul className="mt-3 divide-y divide-line text-sm">
                        {order.lines.map((line) => (
                            <li key={line.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <span className="font-medium text-ink">✓ {line.product_name}</span>
                                    <span className="text-ink-muted"> {line.variant_name ?? ''} × {formatQuantity(line.quantity)}</span>
                                    <p className="text-xs text-ink-faint">{line.addendum ? `Complément #${line.addendum.sequence}` : 'Commande initiale'} · déjà remis</p>
                                </div>
                                <span className="tabular-nums text-ink-muted">{formatMoney(line.total_incl_tax, order.currency_code)}</span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-card border border-line bg-surface p-5">
                    <h2 className="font-semibold text-ink">Ajouter un article</h2>
                    <div className="relative mt-3">
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => changeSearch(event.target.value)}
                            onKeyDown={onSearchKeyDown}
                            placeholder="Nom, SKU, référence ou code-barres"
                            aria-label="Rechercher un produit à ajouter"
                            aria-controls="completion-product-results"
                            className="min-h-10 w-full rounded-field border border-line-strong bg-surface px-3 pr-28 text-sm"
                        />
                        {searching && <span className="absolute inset-y-0 right-3 flex items-center text-xs text-ink-muted">Recherche…</span>}
                    </div>
                    <div id="completion-product-results" className="mt-2 max-h-80 divide-y divide-line overflow-y-auto rounded-field border border-line" role="listbox">
                        {searching && (
                            <p className="px-3 py-4 text-sm text-ink-muted">Recherche des produits…</p>
                        )}
                        {query.trim() === '' && !searching && (
                            <p className="px-3 py-4 text-sm text-ink-muted">Recherchez par nom, référence ou SKU</p>
                        )}
                        {searchError && !searching && (
                            <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-4 text-sm text-danger">
                                <span>{searchError}</span>
                                <button type="button" onClick={() => setRetryToken((value) => value + 1)} className="font-medium underline">Réessayer</button>
                            </div>
                        )}
                        {!searching && !searchError && query.trim() !== '' && results.length === 0 && (
                            <p className="px-3 py-4 text-sm text-ink-muted">Aucun produit trouvé</p>
                        )}
                        {results.map((variant, index) => {
                            const unavailable = isUnavailable(variant);
                            const code = variant.reference ?? variant.sku ?? 'Sans référence';
                            return (
                                <div
                                    key={variant.id}
                                    role="option"
                                    aria-selected={activeResult === index}
                                    onMouseEnter={() => setActiveResult(index)}
                                    className={`flex flex-wrap items-center justify-between gap-3 px-3 py-2.5 ${activeResult === index ? 'bg-raised' : ''}`}
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">{code}{variant.sku && variant.sku !== code ? ` · SKU ${variant.sku}` : ''}</p>
                                        <p className="truncate text-sm font-medium text-ink">{variant.product_name}{variant.variant_name ? ` — ${variant.variant_name}` : ''}</p>
                                        <p className="text-xs text-ink-muted">Stock local : {formatQuantity(variant.local_stock_available)}{variant.config_missing ? ' · Prix/TVA à configurer' : ''}</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="whitespace-nowrap text-sm font-medium text-ink">{variant.unit_price_incl_tax ? formatMoney(variant.unit_price_incl_tax, order.currency_code) : '—'}</span>
                                        <button
                                            type="button"
                                            disabled={unavailable}
                                            onClick={() => add(variant)}
                                            className="min-h-9 rounded-field border border-line-strong px-3 text-sm font-medium text-primary disabled:cursor-not-allowed disabled:text-ink-faint"
                                        >
                                            {cart.some((line) => line.id === variant.id) ? 'Ajouté' : 'Ajouter'}
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>

                {cart.length > 0 && (
                    <section className="rounded-card border border-line bg-surface p-5">
                        <h2 className="font-semibold text-ink">Nouveaux articles</h2>
                        <div className="mt-3 space-y-3">
                            {cart.map((line) => (
                                <div key={line.id} className="grid items-end gap-3 rounded-field border border-line p-3 sm:grid-cols-[1fr_140px_auto]">
                                    <div><p className="font-medium text-ink">{line.product_name}</p><p className="text-xs text-ink-muted">{formatMoney(line.unit_price_incl_tax ?? '0', order.currency_code)} · stock local {formatQuantity(line.local_stock_available)}</p></div>
                                    <label className="text-xs text-ink-muted">Quantité
                                        <input inputMode="decimal" value={line.quantity} onChange={(event) => setQuantity(line.id, event.target.value)} className="mt-1 w-full rounded-field border border-line-strong px-3 py-2 text-sm" />
                                    </label>
                                    <button type="button" onClick={() => setCart(cart.filter((item) => item.id !== line.id))} className="min-h-10 text-sm text-danger">Retirer</button>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                <section className="rounded-card border border-line bg-raised/50 p-5">
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt className="text-ink-muted">Total actuel</dt><dd className="font-semibold">{formatMoney(order.total_incl_tax, order.currency_code)}</dd></div>
                        <div><dt className="text-ink-muted">Ajout</dt><dd className="font-semibold">{formatMoney(addedTotal, order.currency_code)}</dd></div>
                        <div><dt className="text-ink-muted">Nouveau total</dt><dd className="font-semibold">{formatMoney(newTotal, order.currency_code)}</dd></div>
                        <div><dt className="text-ink-muted">Déjà encaissé</dt><dd className="font-semibold">{formatMoney(paymentSummary.paid, order.currency_code)}</dd></div>
                        <div><dt className="text-ink-muted">Reste à payer</dt><dd className="font-semibold text-primary">{formatMoney(newRemaining, order.currency_code)}</dd></div>
                    </dl>
                    {Object.values(form.errors).map((error) => error && <p key={error} className="mt-2 text-sm text-danger">{error}</p>)}
                    <div className="mt-5 flex justify-end gap-2">
                        <Link href={`/sales/orders/${order.id}`} className="inline-flex min-h-10 items-center rounded-field border border-line-strong px-4 text-sm">Annuler</Link>
                        <Button disabled={cart.length === 0} loading={form.processing} loadingText="Validation…" onClick={submit}>Valider l’ajout</Button>
                    </div>
                </section>
            </div>
        </SalesLayout>
    );
}
