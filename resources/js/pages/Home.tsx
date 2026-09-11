import AppPreview from '@/components/marketing/AppPreview';
import { BrandLockup, PRODUCT_NAME } from '@/components/ui/brand';
import { ButtonLink } from '@/components/ui/Button';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

const capabilities: Array<{ title: string; description: string; icon: ReactNode }> = [
    {
        title: 'Point de vente',
        description: 'Encaissez rapidement au comptoir : recherche produit, panier, remises, paiements multiples et reçu.',
        icon: <IconRegister />,
    },
    {
        title: 'Stock multi-emplacements',
        description: 'Suivez les quantités par entrepôt, les mouvements et les transferts, avec un stock société consolidé.',
        icon: <IconBoxes />,
    },
    {
        title: 'Clients & paiements',
        description: 'Fiches clients particuliers ou entreprises, encaissements espèces, carte, virement et chèque.',
        icon: <IconWallet />,
    },
    {
        title: 'Documents de vente',
        description: 'Commandes, factures et bons de livraison générés à partir des mêmes données, sans double saisie.',
        icon: <IconDoc />,
    },
];

export default function Home() {
    return (
        <>
            <Head title={PRODUCT_NAME} />

            <div className="min-h-screen bg-canvas text-ink">
                <header className="mx-auto flex max-w-6xl items-center justify-between px-5 py-5 sm:px-8">
                    <BrandLockup />
                    <div className="flex items-center gap-2">
                        <ButtonLink href="/login" variant="ghost" size="sm">Se connecter</ButtonLink>
                        <ButtonLink href="/register" size="sm">Créer un compte</ButtonLink>
                    </div>
                </header>

                <main className="mx-auto max-w-6xl px-5 sm:px-8">
                    {/* Hero */}
                    <section className="grid items-center gap-10 py-12 lg:grid-cols-[1fr_1.05fr] lg:py-20">
                        <div>
                            <p className="text-[13px] font-medium uppercase tracking-[0.14em] text-ink-muted">Commerce ERP</p>
                            <h1 className="mt-3 text-[34px] font-semibold leading-[1.12] tracking-tight sm:text-[42px]">
                                Gérez vos ventes, votre stock et vos opérations depuis un seul espace.
                            </h1>
                            <p className="mt-4 max-w-xl text-[15px] leading-7 text-ink-muted">
                                {PRODUCT_NAME} réunit le point de vente, le catalogue, le stock multi-emplacements,
                                les clients, les paiements et les documents de vente dans une interface calme et rapide.
                            </p>
                            <div className="mt-7 flex flex-wrap items-center gap-3">
                                <ButtonLink href="/register" size="lg">Créer un compte</ButtonLink>
                                <ButtonLink href="/login" variant="secondary" size="lg">Se connecter</ButtonLink>
                            </div>
                        </div>

                        <div className="lg:pl-4">
                            <AppPreview />
                        </div>
                    </section>

                    {/* Capabilities */}
                    <section className="border-t border-line py-12 lg:py-16">
                        <h2 className="text-lg font-semibold">Ce que vous pilotez</h2>
                        <p className="mt-1 text-sm text-ink-muted">Des modules connectés autour d’une même base de données.</p>
                        <div className="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {capabilities.map((capability) => (
                                <div key={capability.title} className="rounded-card border border-line bg-surface p-5 shadow-card">
                                    <span className="inline-grid size-9 place-items-center rounded-[10px] bg-sage text-primary">{capability.icon}</span>
                                    <h3 className="mt-4 text-[15px] font-semibold">{capability.title}</h3>
                                    <p className="mt-1.5 text-[13px] leading-6 text-ink-muted">{capability.description}</p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* Final CTA */}
                    <section className="mb-16 rounded-panel border border-line bg-sage px-6 py-10 text-center sm:px-10">
                        <h2 className="text-xl font-semibold tracking-tight">Prêt à préparer votre espace de travail ?</h2>
                        <p className="mx-auto mt-2 max-w-md text-sm text-ink-muted">
                            Créez votre compte, ajoutez votre organisation et votre premier magasin, puis commencez à vendre.
                        </p>
                        <div className="mt-6 flex flex-wrap justify-center gap-3">
                            <ButtonLink href="/register" size="lg">Créer un compte</ButtonLink>
                            <ButtonLink href="/login" variant="secondary" size="lg">J’ai déjà un compte</ButtonLink>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-line">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-5 py-6 text-[13px] text-ink-muted sm:px-8">
                        <span>© {new Date().getFullYear()} {PRODUCT_NAME}</span>
                        <div className="flex gap-4">
                            <Link href="/login" className="rounded transition-soft hover:text-ink">Se connecter</Link>
                            <Link href="/register" className="rounded transition-soft hover:text-ink">Créer un compte</Link>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}

function IconRegister() {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="4" width="18" height="13" rx="2" />
            <path d="M3 9h18M8 21h8M12 17v4" />
        </svg>
    );
}
function IconBoxes() {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M4 7l8 4 8-4M12 11v10" />
        </svg>
    );
}
function IconWallet() {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4 7a2 2 0 0 1 2-2h11v4M4 7v10a2 2 0 0 0 2 2h13V9H6a2 2 0 0 1-2-2Z" /><circle cx="16" cy="14" r="1.4" />
        </svg>
    );
}
function IconDoc() {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <path d="M7 3h7l5 5v13H7V3Z" /><path d="M14 3v5h5M10 13h6M10 17h6" />
        </svg>
    );
}
