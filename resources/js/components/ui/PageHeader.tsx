import type { ReactNode } from 'react';
export default function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
    return <header className="mb-7 flex flex-wrap items-start justify-between gap-4"><div><h1 className="text-2xl font-semibold tracking-tight text-slate-950">{title}</h1>{description && <p className="mt-1 max-w-2xl text-sm text-slate-600">{description}</p>}</div>{actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}</header>;
}
