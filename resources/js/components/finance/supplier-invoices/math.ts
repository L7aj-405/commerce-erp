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

const HALF_ULP_SCALE_6 = 500000n; // rounding a scale-8 integer to scale 2 (drop 6 digits)
const DROP_6 = 1000000n;

export type CalculatedLine = { line_ht: string; line_tva: string; line_ttc: string };

/**
 * Mirror of the server SupplierInvoiceCalculator (display only, the server is authoritative):
 * line_ht = round2(qty x unit_price_ht) from the EXACT product, line_tva = round2(line_ht x rate / 100),
 * both half-up; operands are non-negative. Returns null while any operand is blank/invalid/negative.
 */
export function calculateLine(quantity: string, unitPriceHt: string, ratePercent: string): CalculatedLine | null {
    const q = parseScaled(quantity);
    const p = parseScaled(unitPriceHt);
    const r = parseScaled(ratePercent);
    if (q === null || p === null || r === null || q <= 0n || p < 0n || r < 0n) return null;
    const ht2 = (q * p + HALF_ULP_SCALE_6) / DROP_6; // scale 2
    const tva2 = (ht2 * r + HALF_ULP_SCALE_6) / DROP_6; // (scale 2 x scale 4) = scale 6 -> /100 keeps digits at scale 8 -> round to 2
    return { line_ht: formatScaled(ht2 * 100n), line_tva: formatScaled(tva2 * 100n), line_ttc: formatScaled((ht2 + tva2) * 100n) };
}

export type CalculatedTotals = { subtotal_ht: string; total_tva: string; rounding_adjustment: string; total_ttc: string; incomplete: boolean };

/** Sums the rounded lines (+ adjustment). `incomplete` is true when at least one line could not be computed. */
export function calculateTotals(lines: (CalculatedLine | null)[], roundingAdjustment: string): CalculatedTotals {
    let ht = 0n;
    let tva = 0n;
    let ttc = 0n;
    let incomplete = false;
    for (const line of lines) {
        if (!line) {
            incomplete = true;
            continue;
        }
        ht += parseScaled(line.line_ht) ?? 0n;
        tva += parseScaled(line.line_tva) ?? 0n;
        ttc += parseScaled(line.line_ttc) ?? 0n;
    }
    const adjustment = parseScaled(roundingAdjustment.trim() === '' ? '0' : roundingAdjustment);
    if (adjustment === null) incomplete = true;
    return {
        subtotal_ht: formatScaled(ht),
        total_tva: formatScaled(tva),
        rounding_adjustment: formatScaled(adjustment ?? 0n),
        total_ttc: formatScaled(ttc + (adjustment ?? 0n)),
        incomplete,
    };
}
