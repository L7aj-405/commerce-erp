import { createContext, useCallback, useContext, useMemo, useState, type PropsWithChildren } from 'react';
import { ar, fr, type TranslationKey } from './translations';

export type Locale = 'fr' | 'ar';
const STORAGE_KEY = 'erp.locale';

function storedLocale(): Locale {
    if (typeof window === 'undefined') return 'fr';
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'ar' ? 'ar' : 'fr';
    } catch {
        return 'fr';
    }
}

function applyLocale(locale: Locale): void {
    if (typeof document === 'undefined') return;
    document.documentElement.lang = locale;
    document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.dataset.locale = locale;
}

export function initializeLocaleDocument(): Locale {
    const locale = storedLocale();
    applyLocale(locale);
    return locale;
}

type I18nContextValue = {
    locale: Locale;
    direction: 'ltr' | 'rtl';
    setLocale: (locale: Locale) => void;
    t: (key: TranslationKey) => string;
};

const I18nContext = createContext<I18nContextValue | null>(null);

export function I18nProvider({ children }: PropsWithChildren) {
    const [locale, updateLocale] = useState<Locale>(() => storedLocale());
    const setLocale = useCallback((next: Locale) => {
        updateLocale(next);
        applyLocale(next);
        try {
            window.localStorage.setItem(STORAGE_KEY, next);
        } catch {
            /* The selected locale remains active for this page session. */
        }
    }, []);
    const t = useCallback((key: TranslationKey) => (locale === 'ar' ? ar[key] : fr[key]), [locale]);
    const value = useMemo<I18nContextValue>(() => ({ locale, direction: locale === 'ar' ? 'rtl' : 'ltr', setLocale, t }), [locale, setLocale, t]);

    return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nContextValue {
    const context = useContext(I18nContext);
    if (!context) throw new Error('useI18n must be used inside I18nProvider.');
    return context;
}

export function LocaleSwitcher({ compact = false }: { compact?: boolean }) {
    const { locale, setLocale, t } = useI18n();
    return (
        <label className={`flex items-center gap-2 ${compact ? '' : 'px-3 py-2'}`}>
            {!compact && <span className="text-[12px] text-ink-muted">{t('common.language')}</span>}
            <select
                aria-label={t('common.language')}
                value={locale}
                onChange={(event) => setLocale(event.target.value as Locale)}
                className="control-select h-9 min-w-24 text-[12px]"
            >
                <option value="fr">{t('common.french')}</option>
                <option value="ar">{t('common.arabic')}</option>
            </select>
        </label>
    );
}
