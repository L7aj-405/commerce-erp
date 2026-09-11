import { useId, useState } from 'react';
import type { InputHTMLAttributes, ReactNode } from 'react';

/*
 * Shared form primitives so Login, Register and onboarding never restyle inputs
 * by hand. Labels are always real <label>s, errors are associated via aria-describedby.
 */

const fieldBase =
    'h-11 w-full rounded-field border bg-surface px-3.5 text-sm text-ink placeholder:text-ink-faint outline-none transition-soft';

function fieldTone(hasError: boolean) {
    return hasError
        ? 'border-danger/60 focus:border-danger focus:ring-4 focus:ring-danger/10'
        : 'border-line-strong focus:border-primary focus:ring-4 focus:ring-primary/10';
}

export function FieldError({ id, children }: { id?: string; children?: ReactNode }) {
    if (!children) return null;
    return (
        <p id={id} className="mt-1.5 text-[13px] text-danger">
            {children}
        </p>
    );
}

type TextFieldProps = InputHTMLAttributes<HTMLInputElement> & {
    label: string;
    error?: string;
    hint?: string;
};

export function TextField({ label, error, hint, id, className = '', ...props }: TextFieldProps) {
    const autoId = useId();
    const fieldId = id ?? autoId;
    const errorId = `${fieldId}-error`;
    const hintId = `${fieldId}-hint`;

    return (
        <div className={className}>
            <label htmlFor={fieldId} className="mb-1.5 block text-[13px] font-medium text-ink">
                {label}
            </label>
            <input
                id={fieldId}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : hint ? hintId : undefined}
                className={`${fieldBase} ${fieldTone(!!error)}`}
                {...props}
            />
            {hint && !error && (
                <p id={hintId} className="mt-1.5 text-[13px] text-ink-muted">
                    {hint}
                </p>
            )}
            <FieldError id={errorId}>{error}</FieldError>
        </div>
    );
}

export function PasswordField({ label, error, hint, id, className = '', ...props }: TextFieldProps) {
    const autoId = useId();
    const fieldId = id ?? autoId;
    const errorId = `${fieldId}-error`;
    const hintId = `${fieldId}-hint`;
    const [visible, setVisible] = useState(false);

    return (
        <div className={className}>
            <label htmlFor={fieldId} className="mb-1.5 block text-[13px] font-medium text-ink">
                {label}
            </label>
            <div className="relative">
                <input
                    id={fieldId}
                    type={visible ? 'text' : 'password'}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? errorId : hint ? hintId : undefined}
                    className={`${fieldBase} pr-16 ${fieldTone(!!error)}`}
                    {...props}
                />
                <button
                    type="button"
                    onClick={() => setVisible((value) => !value)}
                    className="absolute inset-y-0 right-0 flex items-center rounded-r-field px-3 text-[13px] font-medium text-ink-muted transition-soft hover:text-ink"
                    aria-pressed={visible}
                >
                    {visible ? 'Masquer' : 'Afficher'}
                </button>
            </div>
            {hint && !error && (
                <p id={hintId} className="mt-1.5 text-[13px] text-ink-muted">
                    {hint}
                </p>
            )}
            <FieldError id={errorId}>{error}</FieldError>
        </div>
    );
}

export function Checkbox({ label, id, className = '', ...props }: InputHTMLAttributes<HTMLInputElement> & { label: ReactNode }) {
    const autoId = useId();
    const fieldId = id ?? autoId;

    return (
        <label htmlFor={fieldId} className={`inline-flex cursor-pointer select-none items-center gap-2 text-[13px] text-ink-muted ${className}`}>
            <input
                id={fieldId}
                type="checkbox"
                className="size-4 rounded border-line-strong accent-primary focus-visible:outline-2"
                {...props}
            />
            {label}
        </label>
    );
}

/** Non-field-level message (bad credentials, expired session, etc.). */
export function FormBanner({ tone = 'danger', children }: { tone?: 'danger' | 'success' | 'info'; children: ReactNode }) {
    const tones = {
        danger: 'bg-danger-soft text-danger',
        success: 'bg-success-soft text-success',
        info: 'bg-sage text-ink',
    } as const;

    return (
        <div role="alert" className={`rounded-field px-3.5 py-2.5 text-[13px] ${tones[tone]}`}>
            {children}
        </div>
    );
}
