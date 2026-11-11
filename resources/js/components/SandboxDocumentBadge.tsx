/** Marks a Test Mode document (sandbox_id set). Renders nothing for Production. */
export default function SandboxDocumentBadge({ sandboxId }: { sandboxId?: number | null }) {
    if (sandboxId == null) return null;
    return (
        <span
            title="Document de test — non comptable, sans valeur légale ni fiscale"
            className="inline-flex shrink-0 items-center rounded border border-amber-500 bg-amber-100 px-2 py-0.5 text-xs font-bold tracking-wide text-amber-900"
        >
            TEST · NON COMPTABLE
        </span>
    );
}
