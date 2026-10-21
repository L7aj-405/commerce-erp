import type { ReactNode } from 'react';
export default function PageHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
    return <header className="mb-7 flex flex-wrap items-start justify-between gap-4"><div className="min-w-0"><h1 className="text-2xl font-semibold tracking-tight text-ink sm:text-[1.7rem]">{title}</h1>{description && <p className="mt-1.5 max-w-3xl text-sm leading-6 text-ink-muted">{description}</p>}</div>{actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}</header>;
}
