import { Button } from '@/components/ui/Button';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';

type Props = {
    open: boolean;
    onClose: () => void;
    postUrl: string;
    title: string;
    defaultTo: string;
    defaultSubject: string;
    defaultMessage: string;
    attachmentName: string;
    canSend: boolean;
    mailConfigured: boolean;
    canConfigureMail: boolean;
};

/**
 * Shared "send document by e-mail" dialog for Invoice and Devis. Backend
 * truthfully reports success only after a real SMTP send completes — see
 * OrganizationOutboundMailService — so a submit here either really sent the
 * message or surfaces the exact reason it didn't (not configured / SMTP
 * failure) via `form.errors.email`.
 */
export default function SendDocumentEmailModal({
    open,
    onClose,
    postUrl,
    title,
    defaultTo,
    defaultSubject,
    defaultMessage,
    attachmentName,
    canSend,
    mailConfigured,
    canConfigureMail,
}: Props) {
    const form = useForm({ email: defaultTo, subject: defaultSubject, message: defaultMessage });

    useEffect(() => {
        if (open) {
            form.setData({ email: defaultTo, subject: defaultSubject, message: defaultMessage });
            form.clearErrors();
        }
        // Reset the draft to the document's defaults each time the modal reopens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    if (!open) return null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(postUrl, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-ink/40 px-4" role="dialog" aria-modal="true" aria-label={title}>
            <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-card border border-line bg-surface p-5 shadow-pop">
                <div className="flex items-start justify-between gap-3">
                    <h2 className="min-w-0 text-base font-semibold text-ink">{title}</h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Fermer"
                        className="flex size-9 shrink-0 items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                    >
                        ✕
                    </button>
                </div>

                {!mailConfigured && (
                    <div className="mt-3 rounded-field border border-warning/30 bg-warning-soft px-3 py-2 text-xs text-warning">
                        Configuration e-mail requise. Configurez une adresse d’expédition avant d’envoyer des documents.
                        {canConfigureMail && (
                            <>
                                {' '}
                                <Link href="/email-settings" className="font-medium underline">
                                    Paramètres → Configuration e-mail
                                </Link>
                            </>
                        )}
                    </div>
                )}

                <form onSubmit={submit} className="mt-4 space-y-3 text-sm">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">À</span>
                        <input
                            type="email"
                            required
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            placeholder="destinataire@exemple.ma"
                            className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                        />
                        {form.errors.email && <p className="mt-1 text-xs text-danger">{form.errors.email}</p>}
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">Objet</span>
                        <input
                            type="text"
                            required
                            value={form.data.subject}
                            onChange={(e) => form.setData('subject', e.target.value)}
                            className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                        />
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">Message</span>
                        <textarea
                            rows={5}
                            value={form.data.message}
                            onChange={(e) => form.setData('message', e.target.value)}
                            className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                        />
                    </label>

                    <div className="flex min-w-0 items-center gap-2 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                        <span aria-hidden className="shrink-0">📎</span>
                        <span className="truncate">{attachmentName}</span>
                    </div>

                    {!canSend && (
                        <p className="text-xs text-ink-muted">Vous n’avez pas la permission d’envoyer ce document par e-mail.</p>
                    )}

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" loading={form.processing} loadingText="Envoi…" disabled={!canSend || !mailConfigured}>
                            Envoyer
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}
