import type { SharedPageProps } from '@/types/app';
import { Link, usePage } from '@inertiajs/react';

const TABS = [
    { href: '/finance/commissions', label: 'Tableau de bord', permission: 'commissions.ledger.view' },
    { href: '/finance/commissions/ledger', label: 'Registre', permission: 'commissions.ledger.view' },
    { href: '/finance/commissions/rules', label: 'Règles', permission: 'commissions.rules.view' },
];

/** Section switcher shared by the three Finance commission pages. */
export default function CommissionTabs({ active }: { active: 'dashboard' | 'ledger' | 'rules' }) {
    const { tenant } = usePage<SharedPageProps>().props;
    const keys = { '/finance/commissions': 'dashboard', '/finance/commissions/ledger': 'ledger', '/finance/commissions/rules': 'rules' } as const;
    const visible = TABS.filter((tab) => tenant.permissions.includes(tab.permission));
    if (visible.length < 2) return null;

    return (
        <nav aria-label="Commissions" className="mb-5 flex flex-wrap gap-1 border-b border-line">
            {visible.map((tab) => {
                const isActive = keys[tab.href as keyof typeof keys] === active;
                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        aria-current={isActive ? 'page' : undefined}
                        className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${isActive ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:text-ink'}`}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </nav>
    );
}
