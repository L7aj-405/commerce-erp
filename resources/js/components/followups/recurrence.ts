import type { TranslationKey } from '@/i18n/translations';
import type { RecurringRule } from './types';

type Translate = (key: TranslationKey) => string;

/** Short weekday name for ISO weekday 1 (Monday) … 7 (Sunday), in the UI locale. */
export function weekdayName(day: number, locale: string): string {
    // 2024-01-01 was a Monday.
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-MA' : 'fr-FR', { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, day)));
}

/** "Every Monday at 09:00", "Every 2 weeks on Friday", "Monthly on day 5" — organization-local by construction. */
export function recurrenceSummary(rule: Pick<RecurringRule, 'frequency' | 'interval_count' | 'weekdays' | 'month_day' | 'local_time'>, t: Translate, locale: string): string {
    const n = String(rule.interval_count);
    const days = rule.weekdays.map((day) => weekdayName(day, locale)).join(', ');
    const base = rule.frequency === 'daily'
        ? (rule.interval_count > 1 ? t('work.recEveryDays').replace('{n}', n) : t('work.recDaily'))
        : rule.frequency === 'weekly'
            ? (rule.interval_count > 1 ? t('work.recEveryWeeks').replace('{n}', n) : t('work.recWeekly')).replace('{days}', days)
            : (rule.interval_count > 1 ? t('work.recEveryMonths').replace('{n}', n) : t('work.recMonthly')).replace('{day}', String(rule.month_day ?? ''));

    return rule.local_time ? base + t('work.recAt').replace('{time}', rule.local_time) : base;
}
