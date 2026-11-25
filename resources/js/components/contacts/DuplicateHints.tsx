import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import { useEffect, useRef, useState } from 'react';
import type { ContactValues, DuplicateCandidate } from './types';

type Props = {
    values: Pick<ContactValues, 'display_name' | 'email' | 'phone' | 'ice' | 'kind' | 'reuse_party_id'>;
    /** Only the create flow looks for candidates; an edit never merges. */
    enabled: boolean;
    canReuse: boolean;
    /** Reuse an existing identity: the form then only ADDS the selected roles to it. */
    onReuse: (candidate: DuplicateCandidate) => void;
    onStopReusing: () => void;
    /** Quick transactional forms cannot navigate away: no "open" link there. */
    allowOpen?: boolean;
};

/**
 * Duplicate prevention. Evidence is graded by the server: the same ICE or exactly the same name is "strong"; a shared e-mail or
 * phone is only "weak" (shared mailboxes and numbers are common). Nothing is ever merged automatically: the user opens the
 * existing contact, reuses it, or explicitly continues with a genuinely distinct one.
 */
export default function DuplicateHints({ values, enabled, canReuse, onReuse, onStopReusing, allowOpen = true }: Props) {
    const { t } = useI18n();
    const [candidates, setCandidates] = useState<DuplicateCandidate[]>([]);
    const [dismissed, setDismissed] = useState(false);
    const timer = useRef<number | undefined>(undefined);
    const key = [values.display_name, values.email, values.phone, values.ice, values.kind].join('|');

    useEffect(() => {
        window.clearTimeout(timer.current);
        const enough = values.display_name.trim().length >= 3 || values.email.trim().includes('@') || values.phone.replace(/\D/g, '').length >= 7;
        if (!enabled || !enough) {
            setCandidates([]);
            return;
        }
        const controller = new AbortController();
        timer.current = window.setTimeout(async () => {
            try {
                const query = new URLSearchParams({ display_name: values.display_name, email: values.email, phone: values.phone, ice: values.ice, kind: values.kind });
                const response = await fetch(`/contacts/candidates?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
                if (response.ok) {
                    setCandidates(((await response.json()) as { data: DuplicateCandidate[] }).data);
                    setDismissed(false);
                }
            } catch {
                /* offline or aborted: duplicate hints are advisory, never blocking */
            }
        }, 400);

        return () => {
            window.clearTimeout(timer.current);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, enabled]);

    if (!enabled || candidates.length === 0 || (dismissed && !values.reuse_party_id)) return null;
    const reusing = candidates.find((candidate) => candidate.id === values.reuse_party_id);

    return (
        <Alert tone={candidates.some((c) => c.strength === 'strong') ? 'warning' : 'info'} title={t('cts.dup.title')}>
            <p className="mb-2 text-[13px]">{t('cts.dup.help')}</p>
            <ul className="space-y-2">
                {candidates.map((candidate) => (
                    <li key={candidate.id} className="flex flex-wrap items-center justify-between gap-2 rounded-field border border-line bg-surface px-3 py-2">
                        <span className="min-w-0 text-sm">
                            <span className="font-medium text-ink">{candidate.display_name}</span>
                            <span className="ms-2 text-[13px] text-ink-muted">
                                {[candidate.roles.join(', '), candidate.reasons.map((reason) => t(`cts.dup.reason.${reason}` as never)).join(' · '), candidate.status === 'archived' ? t('cts.archived') : '']
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </span>
                        <span className="flex gap-2">
                            {allowOpen && (
                                <a href={`/contacts/parties/${candidate.id}`} target="_blank" rel="noreferrer" className="inline-flex min-h-9 items-center text-[13px] text-accent underline">
                                    {t('cts.dup.open')}
                                </a>
                            )}
                            {canReuse && candidate.status !== 'archived' && (
                                <Button type="button" size="sm" variant={values.reuse_party_id === candidate.id ? 'primary' : 'outline'} onClick={() => onReuse(candidate)}>
                                    {values.reuse_party_id === candidate.id ? t('cts.dup.reusing') : t('cts.dup.reuse')}
                                </Button>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
            <div className="mt-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        if (reusing) onStopReusing();
                        setDismissed(true);
                    }}
                >
                    {t('cts.dup.continue')}
                </Button>
            </div>
        </Alert>
    );
}
