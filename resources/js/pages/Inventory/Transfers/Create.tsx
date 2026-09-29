import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import SearchInput from '@/components/ui/SearchInput';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatInteger, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

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
type SelectedLine = {
    product_variant_id: number;
    product_name: string;
    label: string | null;
    sku: string;
    reference: string | null;
    brand: string | null;
    available: string;
    quantity: string;
};

const fieldClass = 'h-11 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary disabled:bg-raised disabled:text-ink-faint';
const labelClass = 'block text-xs font-semibold uppercase tracking-wide text-ink-muted';

export default function TransferCreate({ filters, warehouses, searchResults, preselectedVariant }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [selectedLines, setSelectedLines] = useState<SelectedLine[]>([]);
    const form = useForm({
        source_warehouse_id: filters.source_warehouse_id ? String(filters.source_warehouse_id) : '',
        destination_warehouse_id: filters.destination_warehouse_id ? String(filters.destination_warehouse_id) : '',
        reason: '',
        lines: [] as Array<{ product_variant_id: number; quantity: number }>,
    });

    const source = warehouses.find((warehouse) => warehouse.id === Number(form.data.source_warehouse_id));
    const destination = warehouses.find((warehouse) => warehouse.id === Number(form.data.destination_warehouse_id));
    const sameRoute = Boolean(form.data.source_warehouse_id && form.data.source_warehouse_id === form.data.destination_warehouse_id);
    const sourceSelected = form.data.source_warehouse_id !== '';
    const totalUnits = selectedLines.reduce((carry, line) => carry + (Number(line.quantity) || 0), 0);
    const canSubmit = !form.processing && !sameRoute && selectedLines.length > 0 && Boolean(form.data.source_warehouse_id && form.data.destination_warehouse_id && form.data.reason.trim());
    const lineErrors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!preselectedVariant) return;
        setSelectedLines((current) => current.some((line) => line.product_variant_id === preselectedVariant.id) ? current : [
            ...current,
            {
                product_variant_id: preselectedVariant.id,
                product_name: preselectedVariant.product.name,
                label: preselectedVariant.label,
                sku: preselectedVariant.sku,
                reference: preselectedVariant.reference,
                brand: preselectedVariant.product.brand?.name ?? null,
                available: '0.0000',
                quantity: '1',
            },
        ]);
    }, [preselectedVariant?.id]);

    useEffect(() => {
        if (!sourceSelected) return;
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
    }, [search, form.data.source_warehouse_id, form.data.destination_warehouse_id]);

    useEffect(() => {
        setSelectedLines((current) => current.map((line) => {
            const result = searchResults.find((item) => item.id === line.product_variant_id);

            return result ? { ...line, available: result.availability.available } : line;
        }));
    }, [searchResults]);

    const selectedIds = useMemo(() => new Set(selectedLines.map((line) => line.product_variant_id)), [selectedLines]);

    function addResult(result: SearchResult) {
        setSelectedLines((current) => current.some((line) => line.product_variant_id === result.id) ? current : [
            ...current,
            {
                product_variant_id: result.id,
                product_name: result.product.name,
                label: result.label,
                sku: result.sku,
                reference: result.reference,
                brand: result.product.brand?.name ?? null,
                available: result.availability.available,
                quantity: '1',
            },
        ]);
    }

    function removeLine(productVariantId: number) {
        setSelectedLines((current) => current.filter((line) => line.product_variant_id !== productVariantId));
    }

    function setQuantity(productVariantId: number, quantity: string) {
        setSelectedLines((current) => current.map((line) => line.product_variant_id === productVariantId ? { ...line, quantity } : line));
    }

    function submit() {
        form.transform((data) => ({
                ...data,
                lines: selectedLines.map((line) => ({
                    product_variant_id: line.product_variant_id,
                    quantity: Number(line.quantity),
                })),
            }));
        form.post('/inventory/transfers', { preserveScroll: true });
    }

    if (warehouses.length < 2) {
        return (
            <InventoryLayout>
                <Head title="Nouveau transfert" />
                <PageHeader
                    title="Nouveau transfert"
                    description="Déplacez du stock entre deux emplacements de la même organisation."
                    actions={<ButtonLink href="/inventory/transfers" variant="secondary">Retour aux transferts</ButtonLink>}
                />
                <EmptyState
                    title="Deux emplacements sont nécessaires."
                    description="Créez un second emplacement pour pouvoir déplacer du stock entre vos zones de stockage."
                    actions={<ButtonLink href="/inventory/warehouses">Ajouter un emplacement</ButtonLink>}
                />
            </InventoryLayout>
        );
    }

    return (
        <InventoryLayout wide>
            <Head title="Nouveau transfert" />
            <PageHeader
                title="Nouveau transfert"
                description="Préparez un mouvement interne entre deux emplacements. Le stock réel reste validé côté serveur au moment de la création."
                actions={<ButtonLink href="/inventory/transfers" variant="secondary">Retour aux transferts</ButtonLink>}
            />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div className="min-w-0 space-y-6">
                    <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
                        <SectionTitle icon={<IconRoute />} title="Informations du transfert" description="Choisissez le trajet et renseignez le motif opérationnel." />
                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                            <Field label="Emplacement source" error={form.errors.source_warehouse_id}>
                                <select value={form.data.source_warehouse_id} onChange={(event) => form.setData('source_warehouse_id', event.target.value)} className={fieldClass}>
                                    <option value="">Sélectionner la source</option>
                                    {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} · {warehouse.code}</option>)}
                                </select>
                            </Field>
                            <Field label="Emplacement destination" error={form.errors.destination_warehouse_id ?? (sameRoute ? 'La source et la destination doivent être différentes.' : undefined)}>
                                <select value={form.data.destination_warehouse_id} onChange={(event) => form.setData('destination_warehouse_id', event.target.value)} className={fieldClass}>
                                    <option value="">Sélectionner la destination</option>
                                    {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id} disabled={String(warehouse.id) === form.data.source_warehouse_id}>{warehouse.name} · {warehouse.code}</option>)}
                                </select>
                            </Field>
                            <Field label="Motif / observations" error={form.errors.reason} className="md:col-span-2">
                                <textarea
                                    required
                                    value={form.data.reason}
                                    onChange={(event) => form.setData('reason', event.target.value)}
                                    rows={3}
                                    className={`${fieldClass} h-auto py-3`}
                                    placeholder="Ex. rééquilibrage showroom / dépôt, préparation d’un rayon, correction logistique…"
                                />
                            </Field>
                        </div>
                    </section>

                    <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
                        <SectionTitle icon={<IconSearch />} title="Produits à transférer" description="Recherchez les variantes disponibles dans l’emplacement source, puis ajoutez les lignes à transférer." />
                        <div className="mt-5 rounded-field border border-line bg-raised p-3">
                            <SearchInput
                                value={search}
                                onChange={setSearch}
                                searching={searching}
                                placeholder="Rechercher un produit, SKU, référence, code-barres ou marque"
                            />
                        </div>

                        {!sourceSelected ? (
                            <InlineEmpty title="Sélectionnez d’abord une source" description="La disponibilité affichée dépend de l’emplacement source choisi." />
                        ) : searchResults.length === 0 ? (
                            <InlineEmpty title={search ? 'Aucun résultat disponible' : 'Aucun stock disponible'} description={search ? 'Essayez un autre terme de recherche ou vérifiez l’emplacement source.' : 'Aucune variante transférable n’est disponible dans cette source.'} />
                        ) : (
                            <div className="mt-4 grid gap-3 md:grid-cols-2">
                                {searchResults.map((result) => {
                                    const alreadySelected = selectedIds.has(result.id);

                                    return (
                                        <button
                                            key={result.id}
                                            type="button"
                                            onClick={() => addResult(result)}
                                            disabled={alreadySelected}
                                            className="rounded-card border border-line bg-surface p-4 text-left transition-soft hover:border-primary/40 hover:bg-sage disabled:cursor-not-allowed disabled:opacity-55"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold text-ink">{result.product.name}</p>
                                                    <p className="mt-0.5 truncate text-xs text-ink-muted">{result.label ?? 'Variante principale'} · {result.sku}</p>
                                                    <p className="mt-1 truncate text-xs text-ink-faint">{[result.reference, result.product.brand?.name].filter(Boolean).join(' · ') || 'Sans référence'}</p>
                                                </div>
                                                <div className="shrink-0 text-right">
                                                    <p className="text-[11px] uppercase tracking-wide text-ink-faint">Disponible</p>
                                                    <p className="font-semibold tabular-nums text-ink">{formatQuantity(result.availability.available)}</p>
                                                </div>
                                            </div>
                                            <div className="mt-3 flex items-center justify-between gap-3 text-xs">
                                                <span className="text-ink-muted">Stock source : {formatQuantity(result.availability.on_hand)} · réservé : {formatQuantity(result.availability.reserved)}</span>
                                                <span className="font-medium text-primary">{alreadySelected ? 'Déjà ajouté' : 'Ajouter'}</span>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        <div className="mt-6">
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <h3 className="text-sm font-semibold text-ink">Lignes du transfert</h3>
                                {form.errors.lines && <p className="text-sm text-danger">{form.errors.lines}</p>}
                            </div>

                            {selectedLines.length === 0 ? (
                                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center">
                                    <div className="mx-auto grid size-10 place-items-center rounded-full bg-surface text-ink-muted"><IconPackage /></div>
                                    <p className="mt-3 text-sm font-medium text-ink">Aucun produit sélectionné</p>
                                    <p className="mt-1 text-sm text-ink-muted">Ajoutez une ou plusieurs variantes pour préparer le transfert.</p>
                                </div>
                            ) : (
                                <>
                                    <div className="hidden overflow-hidden rounded-card border border-line md:block">
                                        <table className="w-full min-w-[820px] text-left text-sm">
                                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                                <tr>
                                                    <th className="px-4 py-3">Produit</th>
                                                    <th className="px-4 py-3">Variante</th>
                                                    <th className="px-4 py-3 text-right">Stock disponible</th>
                                                    <th className="px-4 py-3 text-right">Quantité</th>
                                                    <th className="px-4 py-3 text-right">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-line">
                                                {selectedLines.map((line, index) => (
                                                    <tr key={line.product_variant_id} className="align-top">
                                                        <td className="px-4 py-3">
                                                            <p className="font-semibold text-ink">{line.product_name}</p>
                                                            <p className="text-xs text-ink-muted">{line.brand ?? 'Sans marque'} · {line.reference ?? 'Sans référence'}</p>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <span className="inline-flex rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">{line.label ?? 'Variante principale'}</span>
                                                            <p className="mt-1 text-xs text-ink-faint">{line.sku}</p>
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-semibold tabular-nums text-ink">{formatQuantity(line.available)}</td>
                                                        <td className="px-4 py-3 text-right">
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={Math.max(1, Number(line.available) || 1)}
                                                                step={1}
                                                                value={line.quantity}
                                                                onChange={(event) => setQuantity(line.product_variant_id, event.target.value)}
                                                                className="ml-auto h-10 w-28 rounded-field border border-line-strong bg-surface px-3 text-right text-sm outline-none transition-soft focus:border-primary"
                                                            />
                                                            {lineErrors[`lines.${index}.quantity`] && <p className="mt-1 text-xs text-danger">{lineErrors[`lines.${index}.quantity`]}</p>}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <button type="button" onClick={() => removeLine(line.product_variant_id)} className="rounded-field px-3 py-2 text-sm font-medium text-danger transition-soft hover:bg-danger-soft">Retirer</button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="space-y-3 md:hidden">
                                        {selectedLines.map((line, index) => (
                                            <article key={line.product_variant_id} className="rounded-card border border-line bg-surface p-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-semibold text-ink">{line.product_name}</p>
                                                        <p className="truncate text-xs text-ink-muted">{line.label ?? 'Variante principale'} · {line.sku}</p>
                                                    </div>
                                                    <button type="button" onClick={() => removeLine(line.product_variant_id)} className="shrink-0 text-xs font-medium text-danger">Retirer</button>
                                                </div>
                                                <dl className="mt-3 grid grid-cols-2 gap-3 rounded-field bg-raised p-3 text-sm">
                                                    <div>
                                                        <dt className="text-xs text-ink-faint">Disponible</dt>
                                                        <dd className="mt-1 font-semibold tabular-nums text-ink">{formatQuantity(line.available)}</dd>
                                                    </div>
                                                    <label>
                                                        <span className="text-xs text-ink-faint">Quantité</span>
                                                        <input
                                                            type="number"
                                                            min={1}
                                                            max={Math.max(1, Number(line.available) || 1)}
                                                            step={1}
                                                            value={line.quantity}
                                                            onChange={(event) => setQuantity(line.product_variant_id, event.target.value)}
                                                            className="mt-1 h-9 w-full rounded-field border border-line-strong bg-surface px-2 text-right text-sm"
                                                        />
                                                    </label>
                                                </dl>
                                                {lineErrors[`lines.${index}.quantity`] && <p className="mt-2 text-xs text-danger">{lineErrors[`lines.${index}.quantity`]}</p>}
                                            </article>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                    </section>
                </div>

                <aside className="space-y-4 xl:sticky xl:top-24 xl:self-start">
                    <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
                        <SectionTitle icon={<IconClipboard />} title="Résumé" description="Vérifiez le trajet et les quantités avant création." compact />
                        <div className="mt-5 space-y-3 text-sm">
                            <SummaryRow label="Source" value={source ? `${source.name} · ${source.code}` : 'Non sélectionnée'} />
                            <SummaryRow label="Destination" value={destination ? `${destination.name} · ${destination.code}` : 'Non sélectionnée'} />
                            <SummaryRow label="Lignes produit" value={formatInteger(selectedLines.length)} />
                            <SummaryRow label="Quantité totale" value={formatQuantity(totalUnits)} strong />
                        </div>
                        {sameRoute && <p className="mt-4 rounded-field border border-warning/30 bg-warning-soft px-3 py-2 text-xs text-warning">La destination doit être différente de la source.</p>}
                        {selectedLines.length === 0 && <p className="mt-4 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">Ajoutez au moins une ligne produit pour créer le transfert.</p>}
                    </section>

                    <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
                        <div className="flex flex-col-reverse gap-2 sm:flex-row xl:flex-col-reverse">
                            <Link href="/inventory/transfers" className="inline-flex min-h-11 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-sage">Annuler</Link>
                            <Button type="button" onClick={submit} disabled={!canSubmit} loading={form.processing} loadingText="Création…">
                                Créer le transfert
                            </Button>
                        </div>
                        <p className="mt-3 text-xs leading-relaxed text-ink-muted">La disponibilité affichée aide à la saisie. La validation finale du stock est réalisée par le serveur au moment de l’enregistrement.</p>
                    </section>
                </aside>
            </div>
        </InventoryLayout>
    );
}

function SectionTitle({ icon, title, description, compact = false }: { icon: React.ReactNode; title: string; description: string; compact?: boolean }) {
    return (
        <div className="flex items-start gap-3">
            <span className="grid size-10 shrink-0 place-items-center rounded-field bg-sage text-primary">{icon}</span>
            <div>
                <h2 className={`${compact ? 'text-base' : 'text-lg'} font-semibold text-ink`}>{title}</h2>
                <p className="mt-1 text-sm text-ink-muted">{description}</p>
            </div>
        </div>
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

function InlineEmpty({ title, description }: { title: string; description: string }) {
    return (
        <div className="mt-4 rounded-card border border-dashed border-line-strong bg-raised px-4 py-6 text-center">
            <p className="text-sm font-medium text-ink">{title}</p>
            <p className="mt-1 text-sm text-ink-muted">{description}</p>
        </div>
    );
}

function SummaryRow({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-line pb-3 last:border-0 last:pb-0">
            <span className="text-ink-muted">{label}</span>
            <span className={`max-w-[60%] text-right ${strong ? 'font-semibold tabular-nums text-ink' : 'font-medium text-ink'}`}>{value}</span>
        </div>
    );
}

const iconProps = { width: 18, height: 18, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
function IconRoute() { return <svg {...iconProps}><circle cx="6" cy="6" r="2" /><circle cx="18" cy="18" r="2" /><path d="M8 6h4a4 4 0 0 1 0 8h-1a4 4 0 0 0 0 8h7" /></svg>; }
function IconSearch() { return <svg {...iconProps}><circle cx="11" cy="11" r="7" /><path d="m16 16 4 4" /></svg>; }
function IconPackage() { return <svg {...iconProps}><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M4 7l8 4 8-4M12 11v10" /></svg>; }
function IconClipboard() { return <svg {...iconProps}><path d="M8 4h8l1 3H7l1-3Z" /><path d="M7 6H5v15h14V6h-2M8 12h8M8 16h6" /></svg>; }
