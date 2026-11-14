import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, PropsWithChildren } from 'react';
import { Spinner } from './Spinner';

/*
 * Action hierarchy — one primary action per area:
 *   primary   → the main commit action (solid V3 violet, highest emphasis)
 *   secondary → alternative actions (solid surface + visible border, never a bare link)
 *   ghost     → low-emphasis toolbar actions
 *   outline   → emphasized secondary action
 *   danger / danger-solid → destructive (solid only for the final confirm)
 */
const styles = {
    primary: 'bg-primary text-primary-fg hover:bg-primary-hover shadow-sm',
    secondary: 'border border-line-strong bg-surface text-ink shadow-xs hover:border-ink-faint hover:bg-sage',
    ghost: 'text-ink-muted hover:bg-sage hover:text-ink',
    outline: 'border border-primary/40 bg-transparent text-primary hover:bg-sage',
    danger: 'border border-danger/40 bg-surface text-danger hover:bg-danger-soft',
    'danger-solid': 'bg-danger text-danger-fg shadow-sm hover:brightness-95',
    success: 'border border-success/30 bg-success-soft text-success hover:bg-success/15',
};

const sizes = {
    sm: 'min-h-9 px-3 text-[13px]',
    md: 'min-h-10 px-4 text-sm',
    lg: 'min-h-11 px-5 text-sm',
};

const iconSizes = {
    sm: 'size-9',
    md: 'size-10',
    lg: 'size-11',
};

type Variant = keyof typeof styles;
type Size = keyof typeof sizes;

const base =
    'relative inline-flex cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-field font-semibold transition-soft active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50 disabled:active:translate-y-0 focus-visible:outline-2 focus-visible:outline-offset-2 [&_svg]:shrink-0';

/**
 * `loading` puts the button into its pending state: a spinner replaces nothing
 * (it is prepended), the button is disabled so it cannot be re-submitted, and
 * `aria-busy` is set. Pass `loadingText` to also swap the label ("Enregistrement…").
 * The button keeps its box so the layout never jumps.
 */
export function Button({
    variant = 'primary',
    size = 'md',
    block = false,
    loading = false,
    loadingText,
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: Variant;
    size?: Size;
    block?: boolean;
    loading?: boolean;
    loadingText?: string;
}) {
    return (
        <button
            {...props}
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            className={`${base} ${styles[variant]} ${sizes[size]} ${block ? 'w-full' : ''} ${loading ? 'cursor-wait! opacity-100!' : ''} ${className}`}
        >
            {loading && <Spinner size="sm" />}
            {loading && loadingText ? loadingText : children}
        </button>
    );
}

export function ButtonLink({
    href,
    variant = 'primary',
    size = 'md',
    block = false,
    className = '',
    title,
    children,
}: PropsWithChildren<{ href: string; variant?: Variant; size?: Size; block?: boolean; className?: string; title?: string }>) {
    return (
        <Link href={href} title={title} className={`${base} ${styles[variant]} ${sizes[size]} ${block ? 'w-full' : ''} ${className}`}>
            {children}
        </Link>
    );
}

/**
 * A file-download button. Deliberately a plain `<a>`, never Inertia's
 * `<Link>` — Inertia's client-side visit expects an Inertia (or full HTML)
 * response, and a raw binary body (XLSX/PDF bytes) gets rendered as text
 * instead of letting the browser download it. `Content-Disposition:
 * attachment` on the server response is what actually triggers the download;
 * this is just a normal top-level navigation that lets the browser honor it.
 */
export function DownloadLink({
    href,
    variant = 'primary',
    size = 'md',
    block = false,
    className = '',
    title,
    children,
}: PropsWithChildren<{ href: string; variant?: Variant; size?: Size; block?: boolean; className?: string; title?: string }>) {
    return (
        <a href={href} title={title} className={`${base} ${styles[variant]} ${sizes[size]} ${block ? 'w-full' : ''} ${className}`}>
            {children}
        </a>
    );
}

/**
 * Icon-only button. `label` is mandatory: it becomes the accessible name and the
 * native tooltip, so icon actions are never anonymous.
 */
export function IconButton({
    label,
    variant = 'ghost',
    size = 'md',
    className = '',
    children,
    ...props
}: Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'aria-label'> & { label: string; variant?: Variant; size?: Size }) {
    return (
        <button type="button" aria-label={label} title={label} {...props} className={`${base} ${styles[variant]} ${iconSizes[size]} p-0 ${className}`}>
            {children}
        </button>
    );
}
