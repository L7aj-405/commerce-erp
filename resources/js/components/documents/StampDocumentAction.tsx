import { Button } from '@/components/ui/Button';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    postUrl: string;
    applied: boolean;
    appliedAt: string | null;
    canStamp: boolean;
};

/**
 * "Apposer le cachet" — a separate, explicit action from the organization's
 * stamp configuration (see DocumentStampApposer). Having a configured stamp
 * never stamps a document by itself; this is the only thing that does, and
 * it is a one-way action per document in V1 (no "un-stamp").
 */
export default function StampDocumentAction({ postUrl, applied, appliedAt, canStamp }: Props) {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const form = useForm({});
    const errors = form.errors as Record<string, string>;

    if (applied) {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-2.5 py-1 text-xs font-medium text-success">
                Cacheté
                {appliedAt && <span className="font-normal text-success/80">· {new Date(appliedAt).toLocaleDateString('fr-FR')}</span>}
            </span>
        );
    }

    if (!canStamp) {
        return null;
    }

    const apply = () => {
        form.post(postUrl, { preserveScroll: true, onSuccess: () => setConfirmOpen(false) });
    };

    return (
        <>
            <Button type="button" variant="secondary" size="sm" onClick={() => setConfirmOpen(true)}>
                Apposer le cachet
            </Button>

            {confirmOpen && (
                <div className="fixed inset-0 z-40 flex items-center justify-center bg-ink/40 px-4" role="dialog" aria-modal="true">
                    <div className="w-full max-w-sm rounded-card border border-line bg-surface p-5 shadow-pop">
                        <h2 className="text-base font-semibold text-ink">Apposer le cachet de l’entreprise ?</h2>
                        <p className="mt-2 text-sm text-ink-muted">Le cachet sera intégré à ce document.</p>
                        {errors.stamp && <p className="mt-2 text-sm text-danger">{errors.stamp}</p>}
                        <div className="mt-4 flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setConfirmOpen(false)}>
                                Annuler
                            </Button>
                            <Button type="button" loading={form.processing} loadingText="Apposition…" onClick={apply}>
                                Apposer le cachet
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
