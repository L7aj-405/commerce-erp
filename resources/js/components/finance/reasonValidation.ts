/** A mandatory "Motif" is missing when it is empty or only whitespace (mirrors the server's trim + required rule). */
export function isReasonBlank(reason: string | null | undefined): boolean {
    return (reason ?? '').trim() === '';
}
