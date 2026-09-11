/**
 * Client mirror of App\Support\PhoneNumber::forWhatsApp — turns a human-entered
 * phone into the international digits-only form a wa.me link needs. Returns null
 * when the value is unusable. Never used to persist anything.
 */
export function toWhatsAppDigits(raw: string | null | undefined): string | null {
    const value = (raw ?? '').trim();
    if (!value) return null;

    const hadPlus = value.startsWith('+');
    let digits = value.replace(/\D+/g, '');
    if (!digits) return null;

    if (digits.startsWith('00')) digits = digits.slice(2);
    if (!hadPlus && digits.length === 10 && digits.startsWith('0')) digits = `212${digits.slice(1)}`;
    if (!hadPlus && digits.length === 9 && ['6', '7'].includes(digits[0])) digits = `212${digits}`;

    return digits.length >= 8 && digits.length <= 15 ? digits : null;
}
