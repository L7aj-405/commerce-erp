import { SandboxBadge, type SandboxBadgeKind } from '@/components/SandboxBadge';

/**
 * Marks a Test Mode document (sandbox_id set) in lists and detail headers. Renders
 * nothing for Production. The generated PDF / print keeps its own strong
 * « DOCUMENT DE TEST » watermark: this is only the on-screen indicator.
 */
export default function SandboxDocumentBadge({ sandboxId, kind = 'document', label }: { sandboxId?: number | null; kind?: SandboxBadgeKind; label?: string }) {
    if (sandboxId == null) return null;
    return <SandboxBadge kind={kind} label={label} />;
}
