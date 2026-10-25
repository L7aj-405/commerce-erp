import { useI18n } from '@/i18n';
import { Link } from '@inertiajs/react';

/** Work Center sections: My Work, Recurring, Templates (managers only). */
export default function WorkNav({ current, canManageAll }: { current: 'work' | 'recurring' | 'templates'; canManageAll: boolean }) {
    const { t } = useI18n();
    const items = [
        { key: 'work', href: '/work', label: t('work.navMyWork') },
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
