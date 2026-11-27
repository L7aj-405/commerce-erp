import { useI18n } from '@/i18n';
import { safeFinanceReturn, withFinanceReturn } from '@/utils/financeReturn';
import { Link, usePage } from '@inertiajs/react';

export { safeFinanceReturn, withFinanceReturn };

export default function FinanceReturnLink() {
    const { t } = useI18n();
    const url = usePage().url;
    const query = new URLSearchParams(url.includes('?') ? url.slice(url.indexOf('?') + 1) : '');
    const target = safeFinanceReturn(query.get('fc_return'));
    if (target === null) return null;
    const account = target.startsWith('/finance/clients/comptes/');

    return (
        <nav aria-label={t('p3.return.label')} className="mb-3">
            <Link href={target} className="inline-flex items-center gap-1.5 rounded-field border border-line bg-surface px-3 py-1.5 text-[13px] font-medium text-ink hover:bg-raised">
                <span aria-hidden="true" className="directional-icon rtl:rotate-180">←</span>
                {account ? t('p3.return.account') : t('p3.return.page')}
            </Link>
        </nav>
    );
}
