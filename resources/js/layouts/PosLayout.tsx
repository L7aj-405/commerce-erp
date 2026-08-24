import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

export default function PosLayout({ children }: PropsWithChildren) {
    return (
        <main className="min-h-screen bg-slate-100 text-slate-950">
            <header className="border-b border-slate-200 bg-white px-4 py-3 shadow-sm sm:px-6">
                <div className="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link href="/platform" className="text-xs font-medium uppercase tracking-wide text-slate-500">Commerce ERP</Link>
                        <h1 className="text-xl font-semibold">Point of Sale</h1>
                    </div>
                    <nav className="flex gap-2 text-sm">
                        <Link href="/sales/orders" className="rounded border border-slate-300 px-3 py-2 hover:bg-slate-50">Sales Orders</Link>
                        <Link href="/platform" className="rounded border border-slate-300 px-3 py-2 hover:bg-slate-50">Tenant selection</Link>
                    </nav>
                </div>
            </header>
            {children}
        </main>
    );
}
