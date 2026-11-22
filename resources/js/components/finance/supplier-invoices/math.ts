/*
 * Display-only decimal helpers on strings (BigInt, scale 4 = DB decimal(19,4)).
 * No floats. The server recomputes and enforces every total; this only gives
 * immediate feedback while editing.
 */
const SCALE = 4;
const FACTOR = 10n ** BigInt(SCALE);

/** Parse "1 234,5" / "1234.50" / "-0.1" into a scaled bigint; null when not a valid decimal. */
export function parseScaled(value: string | null | undefined): bigint | null {
    if (value === null || value === undefined) return null;
    const cleaned = String(value).replace(/[\s  ]/g, '').replace(',', '.');
    const match = /^([+-])?(\d+)(?:\.(\d*))?$/.exec(cleaned);
    if (!match) return null;
    const fraction = (match[3] ?? '').padEnd(SCALE, '0');
    if (/[1-9]/.test(fraction.slice(SCALE))) return null; // more precision than the DB keeps
    const scaled = BigInt(match[2]) * FACTOR + BigInt(fraction.slice(0, SCALE));
    return match[1] === '-' ? -scaled : scaled;
}

export function formatScaled(value: bigint): string {
    const negative = value < 0n;
    const abs = negative ? -value : value;
    const whole = abs / FACTOR;
    const frac = (abs % FACTOR).toString().padStart(SCALE, '0');
    return `${negative ? '-' : ''}${whole}.${frac}`;
}

/** Sum of valid decimal strings; blank entries are skipped, malformed ones are counted in `invalid`. */
export function sumDecimals(values: (string | null | undefined)[]): { sum: string; invalid: number } {
    let total = 0n;
    let invalid = 0;
    for (const v of values) {
        const parsed = parseScaled(v);
        if (parsed === null) {
            if ((v ?? '').trim() !== '') invalid++;
            continue;
        }
        total += parsed;
    }
    return { sum: formatScaled(total), invalid };
}

export function decimalEquals(a: string | null | undefined, b: string | null | undefined): boolean {
    const x = parseScaled(a);
    const y = parseScaled(b);
    return x !== null && y !== null && x === y;
}

/** `a - b` as a decimal string, or null when either side is not a valid decimal. */
export function decimalDiff(a: string | null | undefined, b: string | null | undefined): string | null {
    const x = parseScaled(a);
    const y = parseScaled(b);
    return x === null || y === null ? null : formatScaled(x - y);
}

/** Replace `{name}` placeholders (the shared `t()` has no interpolation). */
export function interpolate(template: string, params: Record<string, string | number>): string {
    return template.replace(/\{(\w+)\}/g, (_, key: string) => (key in params ? String(params[key]) : `{${key}}`));
}
