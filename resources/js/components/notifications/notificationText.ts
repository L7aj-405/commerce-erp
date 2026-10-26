import { fr, type TranslationKey } from '@/i18n/translations';
import type { AppNotification } from '@/types/app';

type Translate = (key: TranslationKey) => string;

const isKey = (value: unknown): value is TranslationKey => typeof value === 'string' && Object.prototype.hasOwnProperty.call(fr, value);

/**
 * Notifications store French text server-side. A producer may add
 * `metadata.i18n = { title, message, params }` (translation keys + values) so
 * the feed renders in the viewer's language; anything else falls back to the
 * stored text.
 */
export function notificationText(item: AppNotification, t: Translate): { title: string; message: string } {
    const i18n = item.metadata?.i18n as { title?: unknown; message?: unknown; params?: unknown } | undefined;
    if (!i18n || typeof i18n !== 'object') return { title: item.title, message: item.message };
    const params = (i18n.params && typeof i18n.params === 'object' ? i18n.params : {}) as Record<string, unknown>;
    const render = (key: unknown, fallback: string) => (isKey(key) ? t(key).replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? '')) : fallback);

    return { title: render(i18n.title, item.title), message: render(i18n.message, item.message) };
}
