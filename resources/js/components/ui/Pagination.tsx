import { Link } from '@inertiajs/react';
import { useI18n } from '@/i18n';
type PageLink = { url: string | null; label: string; active: boolean };
export default function Pagination({ links }: { links: PageLink[] }) {
    const { direction, t } = useI18n();
    const displayLabel = (value: string) => value
        .replace('&laquo; Previous', direction === 'rtl' ? '›' : '‹')
        .replace('Next &raquo;', direction === 'rtl' ? '‹' : '›')
        .replace('&laquo;', direction === 'rtl' ? '›' : '‹')
        .replace('&raquo;', direction === 'rtl' ? '‹' : '›');
    return <nav aria-label={t('common.pagination')} className="mt-6 flex flex-wrap gap-1.5">{links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveScroll preserveState aria-current={link.active ? 'page' : undefined} className={`flex min-h-10 min-w-10 items-center justify-center rounded-field border px-3 py-1.5 text-sm transition-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 ${link.active ? 'border-primary bg-primary text-primary-fg' : 'border-line-strong bg-surface text-ink-muted hover:bg-sage hover:text-ink'}`}>{displayLabel(link.label)}</Link> : null)}</nav>;
}
