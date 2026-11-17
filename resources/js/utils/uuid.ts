/**
 * RFC 4122 v4 id for idempotency keys. `crypto.randomUUID` only exists in secure
 * contexts (https / localhost); on plain http it is undefined and calling it
 * throws, which silently killed the "Vérifier la commande" click handler.
 */
export function newOperationId(): string {
    const webCrypto = typeof globalThis !== 'undefined' ? globalThis.crypto : undefined;
    if (webCrypto && typeof webCrypto.randomUUID === 'function') {
        return webCrypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    if (webCrypto && typeof webCrypto.getRandomValues === 'function') {
        webCrypto.getRandomValues(bytes);
    } else {
        for (let index = 0; index < bytes.length; index++) bytes[index] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
