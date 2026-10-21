import type { SharedPageProps } from '@/types/app';
import { useI18n } from '@/i18n';
import { Link, usePage } from '@inertiajs/react';

/** Section switcher shared by the three Finance commission pages. */
export default function CommissionTabs({ active }: { active: 'dashboard' | 'ledger' | 'rules' }) {
    const { t } = useI18n();
    const tabs = [
        { href: '/finance/commissions', label: t('commissions.dashboard'), permission: 'commissions.ledger.view' },
        { href: '/finance/commissions/ledger', label: t('commissions.ledger'), permission: 'commissions.ledger.view' },
        { href: '/finance/commissions/rules', label: t('commissions.rules'), permission: 'commissions.rules.view' },
    ];
    const { tenant } = usePage<SharedPageProps>().props;
    const keys = { '/finance/commissions': 'dashboard', '/finance/commissions/ledger': 'ledger', '/finance/commissions/rules': 'rules' } as const;
    const visible = tabs.filter((tab) => tenant.permissions.includes(tab.permission));
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
