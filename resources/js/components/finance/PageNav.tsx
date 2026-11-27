import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import { router } from '@inertiajs/react';

/** Server-side pagination footer: keeps every current query parameter and only changes `page`. Grand totals never depend on the page. */
export function PageNav({ page, pages, total, param = 'page' }: { page: number; pages: number; total: number; param?: string }) {
    const { t } = useI18n();
    if (pages <= 1) return null;
    const go = (target: number) => {
        const query = new URLSearchParams(window.location.search);
        query.set(param, String(target));
        router.get(`${window.location.pathname}?${query.toString()}`, {}, { preserveScroll: true, preserveState: true });
    };
    return (
        <div className="flex items-center justify-between gap-3 px-4 py-3 text-sm text-ink-muted">
            <span>{total} {t('fc6.page.rows')} · {t('fc6.page.of')} {page}/{pages}</span>
            <span className="flex gap-2">
                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => go(page - 1)}>{t('fc6.page.prev')}</Button>
                <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => go(page + 1)}>{t('fc6.page.next')}</Button>
            </span>
        </div>
    );
}
