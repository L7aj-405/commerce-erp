import { Button } from '@/components/ui/Button';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, KeyboardEvent } from 'react';
import { useEffect, useMemo, useState } from 'react';

type Recipient = { email: string; name?: string | null; contact_id?: number | null; save_as_contact?: boolean };
type ContactOption = { id: number; full_name: string; company_name: string | null; job_title: string | null; email: string; contact_type: string };
type Bucket = 'to' | 'cc' | 'bcc';
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

const emptyBuckets = (defaultTo: string) => ({
    to: defaultTo ? [{ email: defaultTo }] : ([] as Recipient[]),
    cc: [] as Recipient[],
    bcc: [] as Recipient[],
    subject: '',
    message: '',
});

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
    const form = useForm({ ...emptyBuckets(defaultTo), subject: defaultSubject, message: defaultMessage });
    const [bucket, setBucket] = useState<Bucket>('to');
    const [manualEmail, setManualEmail] = useState('');
    const [manualName, setManualName] = useState('');
    const [saveManual, setSaveManual] = useState(false);
    const [search, setSearch] = useState('');
    const [contacts, setContacts] = useState<ContactOption[]>([]);

    useEffect(() => {
        if (open) {
            form.setData({ ...emptyBuckets(defaultTo), subject: defaultSubject, message: defaultMessage });
            form.clearErrors();
            setBucket('to');
            setManualEmail('');
            setManualName('');
            setSaveManual(false);
            setSearch('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/contacts/lookup?${new URLSearchParams({ search })}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
                .then((response) => (response.ok ? response.json() : { data: [] }))
                .then((payload) => setContacts(payload.data ?? []))
                .catch(() => {});
        }, 200);
        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, search]);

    const allRecipients = useMemo(() => [...form.data.to, ...form.data.cc, ...form.data.bcc], [form.data.to, form.data.cc, form.data.bcc]);
    const hasRecipient = (email: string) => allRecipients.some((recipient) => recipient.email.toLowerCase() === email.toLowerCase());
    const legacyEmailError = (form.errors as Record<string, string | undefined>).email;

    if (!open) return null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(postUrl, { preserveScroll: true, onSuccess: onClose });
    };
    const addRecipient = (target: Bucket, recipient: Recipient) => {
        const email = recipient.email.trim().toLowerCase();
        if (!email || hasRecipient(email)) return;
        form.setData(target, [...form.data[target], { ...recipient, email }]);
    };
    const removeRecipient = (target: Bucket, email: string) => form.setData(target, form.data[target].filter((recipient) => recipient.email !== email));
    const addManual = () => {
        if (!manualEmail.trim()) return;
        addRecipient(bucket, { email: manualEmail, name: manualName || null, save_as_contact: saveManual });
        setManualEmail('');
        setManualName('');
        setSaveManual(false);
    };
    const onManualKey = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            addManual();
        }
    };

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-ink/40 px-4" role="dialog" aria-modal="true" aria-label={title}>
            <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-card border border-line bg-surface p-5 shadow-pop">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="min-w-0 text-base font-semibold text-ink">{title}</h2>
                        <p className="mt-1 text-xs text-ink-muted">Sélectionnez des contacts ou ajoutez des adresses manuellement.</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Fermer" className="flex size-9 shrink-0 items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink">✕</button>
                </div>

                {!mailConfigured && (
                    <div className="mt-3 rounded-field border border-warning/30 bg-warning-soft px-3 py-2 text-xs text-warning">
                        Configuration e-mail requise. Configurez une adresse d’expédition avant d’envoyer des documents.
                        {canConfigureMail && <> <Link href="/email-settings" className="font-medium underline">Paramètres → Configuration e-mail</Link></>}
                    </div>
                )}

                <form onSubmit={submit} className="mt-4 space-y-4 text-sm">
                    <RecipientBucket label="À — Destinataires" recipients={form.data.to} error={form.errors.to} onRemove={(email) => removeRecipient('to', email)} />
                    <RecipientBucket label="CC — En copie" recipients={form.data.cc} error={form.errors.cc} onRemove={(email) => removeRecipient('cc', email)} />
                    <RecipientBucket label="CCI — Copie cachée" recipients={form.data.bcc} error={form.errors.bcc} onRemove={(email) => removeRecipient('bcc', email)} />
                    {legacyEmailError && <p className="text-xs text-danger">{legacyEmailError}</p>}

                    <div className="rounded-card border border-line bg-raised p-3">
                        <div className="mb-3 flex flex-wrap gap-2">
                            {(['to', 'cc', 'bcc'] as Bucket[]).map((key) => <button key={key} type="button" onClick={() => setBucket(key)} className={`rounded-full px-3 py-1 text-xs font-medium ${bucket === key ? 'bg-primary text-primary-fg' : 'bg-surface text-ink-muted'}`}>{key === 'to' ? 'Ajouter à À' : key.toUpperCase()}</button>)}
                        </div>
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Rechercher un contact…" className="mb-3 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                        {contacts.length > 0 && (
                            <div className="mb-3 grid gap-2 sm:grid-cols-2">
                                {contacts.map((contact) => (
                                    <button key={contact.id} type="button" disabled={hasRecipient(contact.email)} onClick={() => addRecipient(bucket, { email: contact.email, name: contact.full_name, contact_id: contact.id })} className="rounded-field border border-line bg-surface px-3 py-2 text-left text-xs transition-soft hover:bg-sage disabled:opacity-45">
                                        <span className="block font-medium text-ink">{contact.full_name}</span>
                                        <span className="block truncate text-ink-muted">{contact.email}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                        <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                            <input type="email" value={manualEmail} onChange={(e) => setManualEmail(e.target.value)} onKeyDown={onManualKey} placeholder="email@exemple.ma" className="rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                            <input value={manualName} onChange={(e) => setManualName(e.target.value)} placeholder="Nom (optionnel)" className="rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                            <button type="button" onClick={addManual} className="rounded-field border border-line-strong bg-surface px-4 py-2 text-sm font-medium text-ink hover:bg-sage">Ajouter</button>
                        </div>
                        <label className="mt-2 flex items-center gap-2 text-xs text-ink-muted">
                            <input type="checkbox" checked={saveManual} onChange={(e) => setSaveManual(e.target.checked)} />
                            Enregistrer cette adresse comme contact
                        </label>
                    </div>

                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">Objet</span>
                        <input type="text" required value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} className="w-full rounded-field border border-line-strong px-3 py-2 text-sm" />
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-muted">Message</span>
                        <textarea rows={5} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} className="w-full rounded-field border border-line-strong px-3 py-2 text-sm" />
                    </label>

                    <div className="flex min-w-0 items-center gap-2 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted"><span aria-hidden className="shrink-0">📎</span><span className="truncate">{attachmentName}</span></div>
                    {!canSend && <p className="text-xs text-ink-muted">Vous n’avez pas la permission d’envoyer ce document par e-mail.</p>}
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>Annuler</Button>
                        <Button type="submit" loading={form.processing} loadingText="Envoi…" disabled={!canSend || !mailConfigured || form.data.to.length === 0}>Envoyer</Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function RecipientBucket({ label, recipients, error, onRemove }: { label: string; recipients: Recipient[]; error?: string; onRemove: (email: string) => void }) {
    return (
        <div>
            <span className="mb-1 block text-xs font-medium text-ink-muted">{label}</span>
            <div className="min-h-11 rounded-field border border-line-strong bg-surface p-1.5">
                {recipients.length === 0 ? <span className="block px-2 py-1.5 text-xs text-ink-faint">Aucun destinataire</span> : (
                    <div className="flex flex-wrap gap-1.5">
                        {recipients.map((recipient) => (
                            <span key={recipient.email} className="inline-flex max-w-full items-center gap-1 rounded-full bg-sage px-2.5 py-1 text-xs text-ink">
                                <span className="truncate">{recipient.name ? `${recipient.name} · ${recipient.email}` : recipient.email}</span>
                                <button type="button" onClick={() => onRemove(recipient.email)} className="text-ink-muted hover:text-danger" aria-label={`Retirer ${recipient.email}`}>×</button>
                            </span>
                        ))}
                    </div>
                )}
            </div>
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
