import { useI18n } from '@/i18n';
import type { Crumb } from '@/layouts/navigation';
import { Link } from '@inertiajs/react';

/** Contextual breadcrumb (registry-derived). Logical order follows the document direction, so RTL flips automatically. */
export default function Breadcrumbs({ crumbs }: { crumbs: Crumb[] }) {
    const { t } = useI18n();
    if (crumbs.length < 2) return null;

    return (
        <nav aria-label={t('breadcrumb.label')} className="mb-3 min-w-0">
            <ol className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-ink-muted">
                {crumbs.map((crumb, index) => {
                    const last = index === crumbs.length - 1;
                    return (
                        <li key={`${index}:${crumb.label}`} className="flex min-w-0 items-center gap-1.5">
                            {index > 0 && (
                                <svg aria-hidden="true" className="directional-icon shrink-0 text-ink-faint rtl:rotate-180" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="m9 5 7 7-7 7" />
                                </svg>
                            )}
                            {crumb.href && !last ? (
                                <Link href={crumb.href} className="truncate rounded hover:text-ink hover:underline focus-visible:outline-offset-2">
                                    {crumb.label}
                                </Link>
                            ) : (
                                <span aria-current={last ? 'page' : undefined} className={`truncate ${last ? 'font-medium text-ink' : ''}`}>
                                    {crumb.label}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
