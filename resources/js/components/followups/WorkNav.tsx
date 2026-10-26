import { useI18n } from '@/i18n';
import type { SharedPageProps } from '@/types/app';
import { Link, usePage } from '@inertiajs/react';

type Section = 'work' | 'team' | 'attention' | 'recurring' | 'templates';

/** Work Center sections: My Work, Team (managers, W5), Attention (document viewers), Recurring, Templates (managers only). */
export default function WorkNav({ current, canManageAll }: { current: Section; canManageAll: boolean }) {
    const { t } = useI18n();
    // Attention covers quotations (W4.1), invoices (W4.4) and sales orders (W4.5): shown to people who may see one of them.
    const permissions = usePage<SharedPageProps>().props.tenant.permissions;
    const canSeeAttention = ['quotations.view', 'invoices.view', 'sales_orders.view'].some((key) => permissions.includes(key));
    const items = [
        { key: 'work', href: '/work', label: t('work.navMyWork') },
        ...(canManageAll ? [{ key: 'team', href: '/work/team', label: t('team.nav') }] : []),
        ...(canSeeAttention ? [{ key: 'attention', href: '/work/attention', label: t('attention.nav') }] : []),
        { key: 'recurring', href: '/work/recurring', label: t('work.navRecurring') },
        ...(canManageAll ? [{ key: 'templates', href: '/work/templates', label: t('work.navTemplates') }] : []),
    ];

    return (
        <nav className="mb-4 flex flex-wrap gap-1" aria-label={t('work.title')}>
            {items.map((item) => (
                <Link
                    key={item.key}
                    href={item.href}
                    aria-current={current === item.key ? 'page' : undefined}
                    className={`rounded-field px-3 py-1.5 text-sm font-medium transition-soft ${current === item.key ? 'bg-sage text-ink' : 'text-ink-muted hover:bg-sage/60 hover:text-ink'}`}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}
