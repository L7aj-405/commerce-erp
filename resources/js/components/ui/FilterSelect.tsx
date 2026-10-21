import type { SelectHTMLAttributes } from 'react';
export default function FilterSelect(props: SelectHTMLAttributes<HTMLSelectElement>) { return <select {...props} className={`control-select h-10 min-w-36 ${props.className ?? ''}`} />; }
