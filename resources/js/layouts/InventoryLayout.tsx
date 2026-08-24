import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

const links = [
    ['/inventory/stock', 'Stock'],
    ['/inventory/warehouses', 'Warehouses'],
    ['/inventory/movements', 'Movements'],
];

export default function InventoryLayout({ children }: PropsWithChildren) {
    return (
        <main className="mx-auto min-h-screen max-w-7xl px-6 py-8">
            <header className="mb-8 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <Link href="/platform" className="text-sm text-slate-500">Commerce ERP</Link>
                    <h1 className="text-2xl font-semibold">Inventory</h1>
                </div>
                <nav className="flex flex-wrap gap-2">
                    {links.map(([href, label]) => (
                        <Link key={href} href={href} className="rounded px-3 py-2 text-sm hover:bg-slate-100">{label}</Link>
                    ))}
                </nav>
            </header>
            {children}
        </main>
    );
}
