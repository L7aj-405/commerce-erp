import { SandboxBadge, SandboxNotice } from '@/components/SandboxBadge';
import { usePage } from '@inertiajs/react';

/**
 * Phase 4 — marks stock quantities as Test stock while Test Mode is active.
 * Renders nothing in Production, so Production stock displays are unchanged.
 */
export default function SandboxStockBadge({ inline = false }: { inline?: boolean }) {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    if (inline) return <SandboxBadge kind="stock" className="ms-1.5" />;
    return (
        <SandboxNotice kind="stock" className="mb-4">
            Quantités initialisées depuis la Production puis indépendantes : aucune opération ici ne modifie le stock réel.
        </SandboxNotice>
    );
}
