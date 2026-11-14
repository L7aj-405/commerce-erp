import type { InputHTMLAttributes } from 'react';
import { TextField } from './form';

/** Legacy API kept for existing callers; renders the shared TextField. */
export default function FormField({ label, error, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }) {
    return <TextField label={label} error={error} {...props} />;
}
