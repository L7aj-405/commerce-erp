import { formatMoney, formatQuantity } from '@/utils/format';
import type { InputHTMLAttributes, ReactNode } from 'react';
import { useEffect } from 'react';

/**
 * Small, consistent POS presentation layer. These components exist so every POS
 * screen renders money, quantities, toggles and numeric inputs the same way —
 * database precision stays on the server, humans see `2 455,00 DH` and `33`.
 */

export function Money({ value, currency = 'MAD', className = '' }: { value: string | number | null | undefined; currency?: string; className?: string }) {
    return <span className={className}>{formatMoney(value, currency)}</span>;
}

export function Quantity({ value, className = '' }: { value: string | number | null | undefined; className?: string }) {
    return <span className={className}>{formatQuantity(value)}</span>;
}

/** Generic segmented control — used for the view toggle, card density, etc. */
export function SegmentedControl<T extends string>({
    value,
    onChange,
    options,
    size = 'md',
    ariaLabel,
}: {
    value: T;
    onChange: (value: T) => void;
    options: Array<{ value: T; label: ReactNode }>;
    size?: 'sm' | 'md';
    ariaLabel?: string;
}) {
    const pad = size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-sm';

    return (
        <div role="group" aria-label={ariaLabel} className="inline-flex rounded-full border border-slate-200 bg-white p-1">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={`rounded-full font-medium transition ${pad} ${value === option.value ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900'}`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

type NumericInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'type'> & {
    value: string;
    onValueChange: (value: string) => void;
    /** Allow a decimal separator. Set false for whole-unit fields. */
    decimals?: boolean;
    onCommit?: (value: string) => void;
};

/**
 * Controlled numeric field. The browser refuses ordinary alphabetic input:
 * only digits and (optionally) a single `.`/`,` separator are accepted, so
 * `abc`, `100dh`, `test` never reach state. The server still validates the
 * exact decimal — this is a UX guard, not the authority.
 */
export function NumericInput({ value, onValueChange, decimals = true, onCommit, className = '', inputMode, ...props }: NumericInputProps) {
    function sanitize(raw: string): string {
        let next = raw.replace(/ /g, '').replace(',', '.');
        next = decimals ? next.replace(/[^0-9.]/g, '') : next.replace(/[^0-9]/g, '');

        if (decimals) {
            const firstDot = next.indexOf('.');
            if (firstDot !== -1) {
                next = next.slice(0, firstDot + 1) + next.slice(firstDot + 1).replace(/\./g, '');
            }
        }

        return next;
    }

    return (
        <input
            {...props}
            type="text"
            inputMode={inputMode ?? (decimals ? 'decimal' : 'numeric')}
            autoComplete="off"
            value={value}
            onChange={(event) => onValueChange(sanitize(event.target.value))}
            onBlur={(event) => {
                const clean = sanitize(event.target.value);
                if (clean !== event.target.value) {
                    onValueChange(clean);
                }
                onCommit?.(clean);
                props.onBlur?.(event);
            }}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    onCommit?.(sanitize((event.target as HTMLInputElement).value));
                }
                props.onKeyDown?.(event);
            }}
            className={className}
        />
    );
}

/** Right-side drawer with backdrop + Escape-to-close. */
export function Drawer({ open, onClose, title, description, children, widthClassName = 'max-w-md' }: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: ReactNode;
    widthClassName?: string;
}) {
    useEffect(() => {
        if (!open) return;
        const handler = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-40 flex justify-end bg-slate-950/30">
            <button type="button" className="flex-1 cursor-default" onClick={onClose} aria-label="Fermer" />
            <aside className={`flex w-full ${widthClassName} flex-col overflow-y-auto bg-white shadow-2xl`}>
                <div className="flex items-start justify-between gap-3 border-b border-slate-200 p-5">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
                        {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Fermer</button>
                </div>
                <div className="flex-1 p-5">{children}</div>
            </aside>
        </div>
    );
}

/** Lightweight anchored popover (click-outside + Escape close). */
export function Popover({ open, onClose, children }: { open: boolean; onClose: () => void; children: ReactNode }) {
    useEffect(() => {
        if (!open) return;
        const handler = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [open, onClose]);

    if (!open) return null;

    return (
        <>
            <button type="button" aria-hidden className="fixed inset-0 z-30 cursor-default" tabIndex={-1} onClick={onClose} />
            <div className="absolute right-0 top-full z-40 mt-2 max-h-[70vh] w-72 max-w-[calc(100vw-1.5rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">
                {children}
            </div>
        </>
    );
}

export function ProductCardSkeleton({ size = 'normal' }: { size?: 'compact' | 'normal' | 'large' }) {
    const aspect = size === 'compact' ? 'aspect-square' : size === 'large' ? 'aspect-[5/4]' : 'aspect-[4/3]';

    return (
        <div className="flex h-full animate-pulse flex-col rounded-2xl border border-slate-200 bg-white p-3">
            <div className={`${aspect} rounded-xl bg-slate-100`} />
            <div className="mt-3 h-4 w-3/4 rounded bg-slate-100" />
            <div className="mt-2 h-3 w-1/2 rounded bg-slate-100" />
            <div className="mt-3 h-5 w-2/5 rounded bg-slate-100" />
            <div className="mt-auto pt-4">
                <div className="h-10 rounded-xl bg-slate-100" />
            </div>
        </div>
    );
}
