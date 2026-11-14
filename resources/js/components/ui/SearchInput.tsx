import { useI18n } from '@/i18n';
import { Spinner } from './Spinner';

export default function SearchInput({ value, onChange, searching, placeholder }: { value: string; onChange: (value: string) => void; searching?: boolean; placeholder?: string }) {
    const { t } = useI18n();
    return (
        <label className="relative block min-w-0 flex-1 sm:min-w-64">
            <span className="sr-only">{t('common.search')}</span>
            <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-faint">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>
            </span>
            <input
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder ?? `${t('common.search')}…`}
                className="control-input h-10 w-full ps-9 pe-10 text-sm"
            />
            {searching && (
                <span className="absolute inset-y-0 end-3 flex items-center text-ink-muted" role="status" aria-label={t('common.searching')}>
                    <Spinner size="sm" />
                </span>
            )}
        </label>
    );
}
