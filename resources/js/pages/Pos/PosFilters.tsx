import { NumericInput } from '@/components/pos/primitives';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { Option } from './types';

export type CatalogueFilters = {
    brandId: number | null;
    categoryId: number | null;
    availability: 'all' | 'in_stock' | 'out_of_stock';
    priceMin: string;
    priceMax: string;
};

export const emptyFilters: CatalogueFilters = {
    brandId: null,
    categoryId: null,
    availability: 'all',
    priceMin: '',
    priceMax: '',
};

export function filtersActive(filters: CatalogueFilters): boolean {
    return (
        filters.brandId !== null ||
        filters.categoryId !== null ||
        filters.availability !== 'all' ||
        filters.priceMin !== '' ||
        filters.priceMax !== ''
    );
}

type Props = {
    brands: Option[];
    categories: Option[];
    value: CatalogueFilters;
    onChange: (next: CatalogueFilters) => void;
};

function Group({ title, defaultOpen = true, children }: { title: string; defaultOpen?: boolean; children: ReactNode }) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className="border-b border-line last:border-b-0">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="flex w-full items-center justify-between py-2.5 text-[13px] font-semibold text-ink"
                aria-expanded={open}
            >
                {title}
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className={`text-ink-faint transition-transform ${open ? '' : '-rotate-90'}`}>
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>
            {open && <div className="pb-3">{children}</div>}
        </div>
    );
}

function OptionRow({ active, label, onClick }: { active: boolean; label: string; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex w-full items-center gap-2 rounded-field px-2 py-1.5 text-left text-[13px] transition-soft ${
                active ? 'bg-sage font-medium text-ink' : 'text-ink-muted hover:bg-sage/60 hover:text-ink'
            }`}
        >
            <span className={`grid size-3.5 shrink-0 place-items-center rounded-full border ${active ? 'border-primary bg-primary' : 'border-line-strong'}`}>
                {active && <span className="size-1.5 rounded-full bg-primary-fg" />}
            </span>
            <span className="truncate">{label}</span>
        </button>
    );
}

export default function PosFilters({ brands, categories, value, onChange }: Props) {
    const set = (patch: Partial<CatalogueFilters>) => onChange({ ...value, ...patch });

    return (
        <aside className="flex h-full w-56 shrink-0 flex-col rounded-card border border-line bg-surface">
            <div className="flex items-center justify-between border-b border-line px-3.5 py-3">
                <h2 className="text-sm font-semibold text-ink">Filtres</h2>
                {filtersActive(value) && (
                    <button type="button" onClick={() => onChange(emptyFilters)} className="text-[12px] font-medium text-ink-muted transition-soft hover:text-ink">
                        Réinitialiser
                    </button>
                )}
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-3.5">
                <Group title="Disponibilité">
                    <div className="space-y-0.5">
                        <OptionRow active={value.availability === 'all'} label="Tous les produits" onClick={() => set({ availability: 'all' })} />
                        <OptionRow active={value.availability === 'in_stock'} label="En stock (société)" onClick={() => set({ availability: 'in_stock' })} />
                        <OptionRow active={value.availability === 'out_of_stock'} label="En rupture" onClick={() => set({ availability: 'out_of_stock' })} />
                    </div>
                </Group>

                <Group title="Prix (DH)">
                    <div className="flex items-center gap-2">
                        <NumericInput
                            value={value.priceMin}
                            onValueChange={(next) => set({ priceMin: next })}
                            placeholder="Min"
                            className="h-9 w-full rounded-field border border-line-strong bg-surface px-2.5 text-[13px] outline-none focus:border-primary"
                        />
                        <span className="text-ink-faint">–</span>
                        <NumericInput
                            value={value.priceMax}
                            onValueChange={(next) => set({ priceMax: next })}
                            placeholder="Max"
                            className="h-9 w-full rounded-field border border-line-strong bg-surface px-2.5 text-[13px] outline-none focus:border-primary"
                        />
                    </div>
                </Group>

                {categories.length > 0 && (
                    <Group title="Catégorie">
                        <div className="max-h-52 space-y-0.5 overflow-y-auto">
                            <OptionRow active={value.categoryId === null} label="Toutes" onClick={() => set({ categoryId: null })} />
                            {categories.map((category) => (
                                <OptionRow
                                    key={category.id}
                                    active={value.categoryId === category.id}
                                    label={category.name}
                                    onClick={() => set({ categoryId: value.categoryId === category.id ? null : category.id })}
                                />
                            ))}
                        </div>
                    </Group>
                )}

                {brands.length > 0 && (
                    <Group title="Marque">
                        <div className="max-h-52 space-y-0.5 overflow-y-auto">
                            <OptionRow active={value.brandId === null} label="Toutes" onClick={() => set({ brandId: null })} />
                            {brands.map((brand) => (
                                <OptionRow
                                    key={brand.id}
                                    active={value.brandId === brand.id}
                                    label={brand.name}
                                    onClick={() => set({ brandId: value.brandId === brand.id ? null : brand.id })}
                                />
                            ))}
                        </div>
                    </Group>
                )}
            </div>
        </aside>
    );
}
