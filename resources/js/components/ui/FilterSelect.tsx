import type { SelectHTMLAttributes } from 'react';
export default function FilterSelect(props: SelectHTMLAttributes<HTMLSelectElement>) { return <select {...props} className={`h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 ${props.className ?? ''}`} />; }
