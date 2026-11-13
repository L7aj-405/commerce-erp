import { useI18n } from '@/i18n';
import { useId, useState } from 'react';
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';

/*
 * Shared form primitives. Labels are always real <label>s, hints/errors are linked
 * via aria-describedby, invalid state is exposed with aria-invalid (styled in
 * app.css), required fields show a visible marker, and errors carry an icon so
 * they never rely on color alone.
 */

type FieldChrome = { label: string; error?: string; hint?: string };

function useFieldIds(id?: string) {
    const autoId = useId();
    const fieldId = id ?? autoId;
    return { fieldId, errorId: `${fieldId}-error`, hintId: `${fieldId}-hint` };
}

function describedBy(error: string | undefined, hint: string | undefined, ids: { errorId: string; hintId: string }) {
    return error ? ids.errorId : hint ? ids.hintId : undefined;
}

export function FieldLabel({ htmlFor, required, children }: { htmlFor?: string; required?: boolean; children: ReactNode }) {
    const { t } = useI18n();
    return (
        <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-medium text-ink">
            {children}
            {required && (
                <>
                    <span aria-hidden="true" className="ms-0.5 text-danger">
                        *
                    </span>
                    <span className="sr-only"> ({t('common.required')})</span>
                </>
            )}
        </label>
    );
}

export function FieldHint({ id, children }: { id?: string; children?: ReactNode }) {
    if (!children) return null;
    return (
        <p id={id} className="mt-1.5 text-[13px] leading-5 text-ink-muted">
            {children}
        </p>
    );
}

export function FieldError({ id, children }: { id?: string; children?: ReactNode }) {
    if (!children) return null;
    return (
        <p id={id} className="mt-1.5 flex items-start gap-1.5 text-[13px] leading-5 font-medium text-danger">
            <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" className="mt-[3px] shrink-0">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7.5v5.5M12 16.5h.01" />
            </svg>
            <span>{children}</span>
        </p>
    );
}

function FieldFooter({ error, hint, ids }: { error?: string; hint?: string; ids: { errorId: string; hintId: string } }) {
    return (
        <>
            {hint && !error && <FieldHint id={ids.hintId}>{hint}</FieldHint>}
            <FieldError id={ids.errorId}>{error}</FieldError>
        </>
    );
}

type TextFieldProps = InputHTMLAttributes<HTMLInputElement> & FieldChrome;

export function TextField({ label, error, hint, id, className = '', required, ...props }: TextFieldProps) {
    const ids = useFieldIds(id);
    return (
        <div className={className}>
            <FieldLabel htmlFor={ids.fieldId} required={required}>
                {label}
            </FieldLabel>
            <input
                id={ids.fieldId}
                required={required}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(error, hint, ids)}
                className="control-input h-11 px-3.5"
                {...props}
            />
            <FieldFooter error={error} hint={hint} ids={ids} />
        </div>
    );
}

export function PasswordField({ label, error, hint, id, className = '', required, ...props }: TextFieldProps) {
    const { t } = useI18n();
    const ids = useFieldIds(id);
    const [visible, setVisible] = useState(false);

    return (
        <div className={className}>
            <FieldLabel htmlFor={ids.fieldId} required={required}>
                {label}
            </FieldLabel>
            <div className="relative">
                <input
                    id={ids.fieldId}
                    type={visible ? 'text' : 'password'}
                    required={required}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy(error, hint, ids)}
                    className="control-input h-11 px-3.5 pe-20"
                    {...props}
                />
                <button
                    type="button"
                    onClick={() => setVisible((value) => !value)}
                    className="absolute inset-y-1 end-1 flex cursor-pointer items-center rounded-[calc(var(--radius-field)-2px)] px-3 text-[13px] font-semibold text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                    aria-pressed={visible}
                    aria-controls={ids.fieldId}
                >
                    {visible ? t('common.hide') : t('common.show')}
                </button>
            </div>
            <FieldFooter error={error} hint={hint} ids={ids} />
        </div>
    );
}

export function Checkbox({ label, id, className = '', ...props }: InputHTMLAttributes<HTMLInputElement> & { label: ReactNode }) {
    const autoId = useId();
    const fieldId = id ?? autoId;

    return (
        <label htmlFor={fieldId} className={`inline-flex min-h-9 cursor-pointer select-none items-center gap-2.5 text-sm text-ink ${className}`}>
            <input id={fieldId} type="checkbox" className="size-[18px] shrink-0 cursor-pointer rounded border-line-strong accent-primary" {...props} />
            {label}
        </label>
    );
}

export function SelectField({
    label,
    error,
    hint,
    id,
    className = '',
    required,
    children,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement> & FieldChrome & { children: ReactNode }) {
    const ids = useFieldIds(id);
    return (
        <div className={className}>
            <FieldLabel htmlFor={ids.fieldId} required={required}>
                {label}
            </FieldLabel>
            <select
                id={ids.fieldId}
                required={required}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(error, hint, ids)}
                className="control-select h-11"
                {...props}
            >
                {children}
            </select>
            <FieldFooter error={error} hint={hint} ids={ids} />
        </div>
    );
}

export function TextAreaField({ label, error, hint, id, className = '', required, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & FieldChrome) {
    const ids = useFieldIds(id);
    return (
        <div className={className}>
            <FieldLabel htmlFor={ids.fieldId} required={required}>
                {label}
            </FieldLabel>
            <textarea
                id={ids.fieldId}
                required={required}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(error, hint, ids)}
                className="control-base min-h-28 px-3.5 py-3 text-sm leading-6"
                {...props}
            />
            <FieldFooter error={error} hint={hint} ids={ids} />
        </div>
    );
}

/** Non-field-level message (bad credentials, expired session, etc.). Kept for compatibility; renders an Alert. */
export function FormBanner({ tone = 'danger', children }: { tone?: 'danger' | 'success' | 'info'; children: ReactNode }) {
    return (
        <div
            role="alert"
            className={`flex items-start gap-2.5 rounded-field border px-3.5 py-2.5 text-sm ${
                tone === 'danger'
                    ? 'border-danger/30 bg-danger-soft text-danger'
                    : tone === 'success'
                      ? 'border-success/30 bg-success-soft text-success'
                      : 'border-info/25 bg-info-soft text-info'
            }`}
        >
            {children}
        </div>
    );
}
