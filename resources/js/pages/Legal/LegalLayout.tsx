import { BrandLockup } from '@/components/ui/brand';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type LegalMetadata = {
    productName: string;
    operatorName: string | null;
    contactEmail: string | null;
    lastUpdated: string;
    productionUrl: string;
};

export type LegalLinks = {
    privacy: string;
    terms: string;
};

type Props = {
    title: string;
    description: string;
    legal: LegalMetadata;
    legalLinks: LegalLinks;
    children: ReactNode;
};

export default function LegalLayout({ title, description, legal, legalLinks, children }: Props) {
    const contactLabel = legal.contactEmail ?? 'Adresse de contact à compléter avant la mise en production';

    return (
        <>
            <Head title={title} />

            <div className="min-h-screen bg-canvas text-ink">
                <header className="border-b border-line bg-surface/80">
                    <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-5 py-5 sm:px-8">
                        <Link href="/" className="rounded-lg">
                            <BrandLockup />
                        </Link>
                        <nav className="flex flex-wrap items-center gap-4 text-sm text-ink-muted">
                            <Link href="/login" className="rounded transition-soft hover:text-ink">Se connecter</Link>
                            <Link href={legalLinks.privacy} className="rounded transition-soft hover:text-ink">Confidentialité</Link>
                            <Link href={legalLinks.terms} className="rounded transition-soft hover:text-ink">Conditions</Link>
                        </nav>
                    </div>
                </header>

                <main className="mx-auto max-w-5xl px-5 py-10 sm:px-8 sm:py-14">
                    <div className="rounded-panel border border-line bg-surface p-6 shadow-card sm:p-9">
                        <p className="text-[13px] font-medium uppercase tracking-[0.14em] text-primary">{legal.productName}</p>
                        <h1 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
                        <p className="mt-3 max-w-3xl text-sm leading-7 text-ink-muted">{description}</p>
                        <div className="mt-5 grid gap-2 rounded-card border border-line bg-canvas p-4 text-sm text-ink-muted sm:grid-cols-3">
                            <p><span className="font-medium text-ink">Dernière mise à jour :</span><br />{legal.lastUpdated}</p>
                            <p><span className="font-medium text-ink">Opérateur :</span><br />{legal.operatorName ?? 'À compléter par l’éditeur'}</p>
                            <p><span className="font-medium text-ink">Contact :</span><br />{legal.contactEmail ? <a className="underline-offset-4 hover:underline" href={`mailto:${legal.contactEmail}`}>{contactLabel}</a> : contactLabel}</p>
                        </div>
                    </div>

                    <article className="mt-8 space-y-7 rounded-panel border border-line bg-surface p-6 text-sm leading-7 text-ink-muted shadow-card sm:p-9">
                        {children}
                    </article>
                </main>

                <footer className="border-t border-line">
                    <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-5 py-6 text-[13px] text-ink-muted sm:px-8">
                        <span>© {new Date().getFullYear()} {legal.productName}</span>
                        <div className="flex gap-4">
                            <Link href={legalLinks.privacy} className="rounded transition-soft hover:text-ink">Confidentialité</Link>
                            <Link href={legalLinks.terms} className="rounded transition-soft hover:text-ink">Conditions d’utilisation</Link>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}

export function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section>
            <h2 className="text-lg font-semibold tracking-tight text-ink">{title}</h2>
            <div className="mt-2 space-y-3">{children}</div>
        </section>
    );
}

export function BulletList({ children }: { children: ReactNode }) {
    return <ul className="ml-5 list-disc space-y-1">{children}</ul>;
}
