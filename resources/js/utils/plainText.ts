const ENTITIES: Record<string, string> = { nbsp: ' ', amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", rsquo: '’', lsquo: '‘', hellip: '…', eacute: 'é', egrave: 'è', agrave: 'à', ccedil: 'ç' };

/**
 * Readable plain text from a stored description that may contain markup
 * (e.g. WooCommerce imports: `<p>`, `<strong>`, `<br />`). Display only and
 * text-only: the result is rendered by React as text, never as HTML, so no
 * markup or script from the stored value can execute. The stored data is
 * unchanged.
 */
export function plainText(value: string | null | undefined): string {
    if (!value) return '';
    if (!/[<&]/.test(value)) return value.trim();

    return value
        .replace(/<\s*(script|style)[^>]*>[\s\S]*?<\/\s*\1\s*>/gi, '')
        .replace(/<\s*br\s*\/?>/gi, '\n')
        .replace(/<\s*li[^>]*>/gi, '• ')
        .replace(/<\/\s*(p|div|li|ul|ol|h[1-6]|tr|blockquote)\s*>/gi, '\n')
        .replace(/<[^>]*>/g, '')
        .replace(/&(#x?[0-9a-f]+|[a-z]+);/gi, (entity, code: string) => {
            if (code[0] === '#') {
                const point = code[1]?.toLowerCase() === 'x' ? parseInt(code.slice(2), 16) : parseInt(code.slice(1), 10);
                return Number.isFinite(point) && point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : entity;
            }
            return ENTITIES[code.toLowerCase()] ?? entity;
        })
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}
