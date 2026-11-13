import type { SelectHTMLAttributes } from 'react';

/**
 * Compact toolbar select. `w-auto` overrides `.control-select`'s full width so
 * filters sit side by side instead of each taking a whole row. Pass `aria-label`
 * (the first option is usually a placeholder, not a label).
 */
export default function FilterSelect(props: SelectHTMLAttributes<HTMLSelectElement>) {
    return <select {...props} className={`control-select h-10 w-auto min-w-36 max-w-full ${props.className ?? ''}`} />;
}
