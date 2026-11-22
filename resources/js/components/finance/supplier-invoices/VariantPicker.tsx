import { SearchableSelect } from '@/components/ui/SearchableSelect';
import type { SelectOption } from '@/components/ui/SearchableSelect';
import { useI18n } from '@/i18n';
import { useEffect, useMemo, useState } from 'react';

export type VariantHit = { id: number; sku: string | null; label: string | null; product_name: string | null };

const MIN_CHARS = 2;
const labelOf = (hit: VariantHit) => [hit.product_name, hit.label].filter(Boolean).join(' · ') || `#${hit.id}`;
const toOption = (hit: VariantHit): SelectOption => ({ value: String(hit.id), label: labelOf(hit), description: hit.sku ?? undefined });

/**
 * Debounced server-side article search for stock lines without a purchase-order line.
 * GET {searchUrl}?q=  ->  { data: [{id, sku, label, product_name}] } (min 2 chars, max 20; no cost data).
 * Built on the shared SearchableSelect (ARIA combobox, keyboard operable); shows loading, empty and error states.
 */
export function VariantPicker({
    searchUrl,
    value,
    selectedLabel,
    onSelect,
    error,
    required = true,
}: {
    searchUrl: string;
    value: number | null;
    selectedLabel: string | null;
    onSelect: (variant: { id: number; label: string } | null) => void;
    error?: string;
    required?: boolean;
}) {
    const { t } = useI18n();
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<VariantHit[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const term = query.trim();
        if (term.length < MIN_CHARS) {
            setHits([]);
            setLoading(false);
            setFailed(false);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch(`${searchUrl}?${new URLSearchParams({ q: term })}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
                if (!response.ok) throw new Error(String(response.status));
                const json = (await response.json()) as { data?: VariantHit[] };
                setHits(json.data ?? []);
                setFailed(false);
            } catch (caught) {
                if ((caught as { name?: string }).name === 'AbortError') return;
                setHits([]);
                setFailed(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 300);
        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, searchUrl]);

    const options = useMemo(() => {
        const list = hits.map(toOption);
        if (value !== null && !list.some((o) => o.value === String(value))) list.unshift({ value: String(value), label: selectedLabel ?? `#${value}` });
        return list;
    }, [hits, value, selectedLabel]);

    const short = query.trim().length < MIN_CHARS;
    return (
        <SearchableSelect
            label={t('supplierInvoice.form.article')}
            required={required}
            options={options}
            value={value === null ? null : String(value)}
            onChange={(next, option) => onSelect(next === null || option === null ? null : { id: Number(next), label: option.description ? `${option.label} · ${option.description}` : option.label })}
            onQueryChange={setQuery}
            loading={loading}
            placeholder={t('supplierInvoice.variant.placeholder')}
            emptyLabel={failed ? t('supplierInvoice.variant.error') : short ? t('supplierInvoice.variant.minChars') : t('supplierInvoice.variant.empty')}
            error={error}
        />
    );
}
