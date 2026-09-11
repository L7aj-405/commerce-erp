import AppPreview from '@/components/marketing/AppPreview';
import { BrandLockup } from '@/components/ui/brand';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Props = {
    title: string;
    subtitle?: string;
    children: ReactNode;
    footer?: ReactNode;
    /** Line shown on the brand panel under the pitch. */
    aside?: string;
};

export default function AuthLayout({ title, subtitle, children, footer, aside }: Props) {
    return (
        <div className="min-h-screen bg-canvas lg:grid lg:grid-cols-[1.05fr_1fr]">
            {/* Brand panel */}
            <aside className="relative hidden flex-col justify-between overflow-hidden bg-sage px-10 py-10 lg:flex xl:px-14">
                <Link href="/" className="w-fit rounded-lg">
                    <BrandLockup />
                </Link>

                <div className="max-w-md">
                    <h2 className="text-[26px] font-semibold leading-tight tracking-tight text-ink">
                        Vos ventes, votre stock et vos points de vente au même endroit.
                    </h2>
                    <p className="mt-3 text-sm leading-6 text-ink-muted">
                        {aside ?? 'Une base opérationnelle claire pour piloter le commerce au quotidien : catalogue, stock multi-emplacements, clients, paiements et documents de vente.'}
                    </p>
                </div>

                <AppPreview className="max-w-lg" />
            </aside>

            {/* Form panel */}
            <main className="flex min-h-screen flex-col px-5 py-8 sm:px-8 lg:py-12">
                <div className="lg:hidden">
                    <Link href="/" className="w-fit rounded-lg">
                        <BrandLockup />
                    </Link>
                </div>

                <div className="flex flex-1 items-center justify-center">
                    <div className="w-full max-w-[400px]">
                        <div className="mb-6">
                            <h1 className="text-2xl font-semibold tracking-tight text-ink">{title}</h1>
                            {subtitle && <p className="mt-1.5 text-sm text-ink-muted">{subtitle}</p>}
                        </div>

                        {children}

                        {footer && <div className="mt-6 text-center text-[13px] text-ink-muted">{footer}</div>}
                    </div>
                </div>
            </main>
        </div>
    );
}
