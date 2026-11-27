/**
 * Where a Finance clients page sent the user from (customer account tab, register filters, ...). The origin travels as the
 * `fc_return` query parameter and is only honoured when it is a same-site Finance clients path: anything else is ignored.
 */
export function safeFinanceReturn(raw: string | null | undefined): string | null {
    if (!raw || !raw.startsWith('/finance/clients/') || raw.startsWith('//') || raw.includes(String.fromCharCode(92)) || /[\r\n]/u.test(raw)) return null;

    return raw;
}

const LEGACY_PREFIX = new RegExp('^/finance/(facturation|encaissements|creances|journal)(?=[/?]|$)', 'u');

/** `href` with the current Finance clients location attached as its return target (legacy URLs map to their Finance clients alias). */
export function withFinanceReturn(href: string, from: string): string {
    const target = from.replace(LEGACY_PREFIX, '/finance/clients/$1');

    return `${href}${href.includes('?') ? '&' : '?'}fc_return=${encodeURIComponent(target)}`;
}
