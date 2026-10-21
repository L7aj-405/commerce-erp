import { useI18n } from '@/i18n';

export default function SearchInput({ value, onChange, searching, placeholder }: { value: string; onChange: (value: string) => void; searching?: boolean; placeholder?: string }) {
    const { t } = useI18n();
    return <label className="relative block min-w-0 flex-1 sm:min-w-64"><span className="sr-only">{t('common.search')}</span><input type="search" value={value} onChange={event => onChange(event.target.value)} placeholder={placeholder ?? `${t('common.search')}…`} className="control-input h-10 w-full pe-24 text-sm" />{searching && <span className="absolute end-3 top-2.5 text-xs text-slate-500">{t('common.searching')}</span>}</label>;
}
