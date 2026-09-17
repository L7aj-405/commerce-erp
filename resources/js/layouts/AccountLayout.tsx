import ApplicationShell from '@/layouts/ApplicationShell';
import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

const TABS = [
    { href: '/account/profile', label: 'Profil' },
    { href: '/security', label: 'Sécurité' },
];

/**
 * Personal "Account Settings" area — the authenticated user's OWN profile
 * and security, distinct from organization administration ("Utilisateurs &
 * accès" stays under `/organizations/{organization}/users-access`). A
 * two-tab sub-nav ties `/account/profile` and `/security` together so they
 * read as one section instead of two unrelated pages.
 */
export default function AccountLayout({ children }: PropsWithChildren) {
    const currentPath = usePage().url.split('?')[0];

    return (
        <ApplicationShell>
            <div className="mx-auto max-w-2xl">
                <nav aria-label="Compte" className="mb-6 flex gap-1 overflow-x-auto border-b border-line">
                    {TABS.map((tab) => {
                        const active = currentPath === tab.href;
                        return (
                            <Link
                                key={tab.href}
                                href={tab.href}
                                aria-current={active ? 'page' : undefined}
                                className={`flex min-h-11 shrink-0 items-center border-b-2 px-3.5 text-sm font-medium transition-soft ${
                                    active ? 'border-primary text-ink' : 'border-transparent text-ink-muted hover:text-ink'
                                }`}
                            >
                                {tab.label}
                            </Link>
                        );
                    })}
                </nav>
                {children}
            </div>
        </ApplicationShell>
    );
}
