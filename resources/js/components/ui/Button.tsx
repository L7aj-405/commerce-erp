import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, PropsWithChildren } from 'react';
import { Spinner } from './Spinner';

const styles = {
    primary: 'bg-primary text-primary-fg hover:bg-primary-hover shadow-sm',
    secondary: 'border border-line-strong bg-surface text-ink hover:bg-raised',
    ghost: 'text-ink-muted hover:bg-sage hover:text-ink',
    danger: 'border border-danger/30 text-danger hover:bg-danger-soft',
};

const sizes = {
    sm: 'min-h-8 px-3 text-xs',
    md: 'min-h-10 px-4 text-sm',
    lg: 'min-h-11 px-5 text-sm',
};

type Variant = keyof typeof styles;
type Size = keyof typeof sizes;

const base = 'inline-flex items-center justify-center gap-2 rounded-field font-medium transition-soft disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-2';

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
            className={`${base} ${styles[variant]} ${sizes[size]} ${block ? 'w-full' : ''} ${className}`}
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
