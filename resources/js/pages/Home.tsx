import { Head, Link } from '@inertiajs/react';

export default function Home() {
    return (
        <>
            <Head title="Commerce ERP" />

            <main className="mx-auto flex min-h-screen max-w-3xl flex-col justify-center gap-4 px-6">
                <h1>Commerce ERP</h1>
                <p>Laravel + React + Inertia + TypeScript + Vite is working.</p>
                <Link href="/login" className="w-fit rounded bg-slate-900 px-4 py-2 text-white">
                    Sign in
                </Link>
            </main>
        </>
    );
}
