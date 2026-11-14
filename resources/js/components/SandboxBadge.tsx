import { FlaskConicalIcon } from '@/components/ui/icons';
import type { ReactNode } from 'react';

/**
 * The single visual language for every Test Mode marker: a compact pill with a
 * small flask icon, a subtle border and a soft theme-token background. Domain
 * wrappers (SandboxStockBadge, SandboxPaymentBadge, …) decide WHEN to show it;
 * this file decides how it looks. Test copy is French only (see Phase 2 notes).
 */
export type SandboxBadgeKind =
    | 'test'
    | 'stock'
    | 'payment'
    | 'document'
    | 'quotation'
    | 'invoice'
    | 'delivery'
    | 'credit'
    | 'procurement'
    | 'fulfillment'
    | 'return'
    | 'exchange'
    | 'email';

const LABELS: Record<SandboxBadgeKind, string> = {
    test: 'Mode test',
    stock: 'Stock test',
    payment: 'Paiement test',
    document: 'Document test',
    quotation: 'Devis test',
    invoice: 'Facture test',
    delivery: 'BL test',
    credit: 'Avoir test',
    procurement: 'Achats test',
    fulfillment: 'Expédition test',
    return: 'Retour test',
    exchange: 'Échange test',
    email: 'E-mail test',
};

const HINTS: Record<SandboxBadgeKind, string> = {
    test: 'Donnée de test : aucun effet sur la Production',
    stock: 'Quantités du stock test, indépendantes de la Production',
    payment: 'Paiement simulé : aucun effet sur la caisse, la banque ou le TPE réels',
    document: 'Document de test : non comptable, sans valeur légale ni fiscale',
    quotation: 'Devis de test : sans valeur commerciale ni légale',
    invoice: 'Facture de test : non comptable, sans valeur légale ni fiscale',
    delivery: 'Bon de livraison de test : sans valeur légale',
    credit: 'Avoir de test : sans valeur fiscale',
    procurement: 'Approvisionnement test : aucun effet sur les achats, réceptions ou le stock de Production',
    fulfillment: 'Expédition test : stock test uniquement, aucun transporteur ni client contacté',
    return: 'Retour test : stock test uniquement, remboursement simulé',
    exchange: 'Échange test : stock test uniquement, règlement simulé',
    email: 'E-mail simulé : aucun message réel n’est envoyé',
};

/*
 * One shape for every Test badge (height, radius, padding, type, icon); only the tint
 * carries the domain, so a screen reads at a glance without any debug-style block:
 *   stock → green · payment → blue · documents → indigo · return/exchange → amber
 *   fulfillment → cyan · procurement → neutral · generic / e-mail → teal (as the top bar)
 * Every pair is a theme token with AA text contrast on its soft fill, light and dark.
 */
const TONES = {
    green: 'border-success/30 bg-success-soft text-success',
    blue: 'border-info/30 bg-info-soft text-info',
    indigo: 'border-indigo/30 bg-indigo-soft text-indigo',
    amber: 'border-warning/30 bg-warning-soft text-warning',
    cyan: 'border-cyan/30 bg-cyan-soft text-cyan',
    neutral: 'border-line-strong bg-sage text-ink-muted',
    teal: 'border-accent/30 bg-accent-soft text-accent',
} as const;

const TONE_OF: Record<SandboxBadgeKind, keyof typeof TONES> = {
    test: 'teal',
    email: 'teal',
    stock: 'green',
    payment: 'blue',
    document: 'indigo',
    quotation: 'indigo',
    invoice: 'indigo',
    delivery: 'indigo',
    credit: 'indigo',
    return: 'amber',
    exchange: 'amber',
    fulfillment: 'cyan',
    procurement: 'neutral',
};

export function SandboxBadge({ kind = 'test', label, title, className = '' }: { kind?: SandboxBadgeKind; label?: string; title?: string; className?: string }) {
    return (
        <span
            title={title ?? HINTS[kind]}
            className={`inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border px-2 align-middle text-[11.5px] font-semibold leading-none ${TONES[TONE_OF[kind]]} ${className}`}
        >
            <FlaskConicalIcon size={12} className="shrink-0" />
            {label ?? LABELS[kind]}
        </span>
    );
}

/** Compact notice row: the badge plus one muted sentence. Used where the screen needs more than a pill. */
export function SandboxNotice({ kind, label, children, className = '' }: { kind: SandboxBadgeKind; label?: string; children: ReactNode; className?: string }) {
    return (
        <div role="note" className={`mb-3 flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-field border border-line bg-raised px-3 py-1.5 text-[13px] text-ink-muted ${className}`}>
            <SandboxBadge kind={kind} label={label} />
            <span className="min-w-0">{children}</span>
        </div>
    );
}
