import { Button } from '@/components/ui/Button';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';

type Anchor = 'bottom_left' | 'bottom_right' | 'top_left' | 'top_right';

type Stamp = {
    id: number;
    position_anchor: Anchor;
    offset_x_mm: number;
    offset_y_mm: number;
    display_width_mm: number;
    rotation_deg: number;
    source: 'uploaded' | 'digitized';
    uploaded_at: string;
};

type Bounds = {
    anchors: Anchor[];
    min_display_width_mm: number;
    max_display_width_mm: number;
    max_offset_x_mm: number;
    max_offset_y_mm: number;
    min_rotation_deg: number;
    max_rotation_deg: number;
};

type Props = {
    organization: { id: number; name: string };
    stamp: Stamp | null;
    bounds: Bounds;
    canUpdate: boolean;
};

const ANCHOR_LABELS: Record<Anchor, string> = {
    bottom_left: 'Bas gauche',
    bottom_right: 'Bas droite',
    top_left: 'Haut gauche',
    top_right: 'Haut droite',
};

export default function DocumentStamp({ stamp, bounds, canUpdate }: Props) {
    const [confirmRemove, setConfirmRemove] = useState(false);
    const [cacheBust, setCacheBust] = useState(0);

    const form = useForm<{
        image: File | null;
        position_anchor: Anchor;
        offset_x_mm: number;
        offset_y_mm: number;
        display_width_mm: number;
        rotation_deg: number;
    }>({
        image: null,
        position_anchor: stamp?.position_anchor ?? 'bottom_left',
        offset_x_mm: stamp?.offset_x_mm ?? 0,
        offset_y_mm: stamp?.offset_y_mm ?? 5,
        display_width_mm: stamp?.display_width_mm ?? 35,
        rotation_deg: stamp?.rotation_deg ?? 0,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/document-stamp', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.setData('image', null);
                setCacheBust((n) => n + 1);
            },
        });
    };

    const remove = () => {
        form.delete('/document-stamp', { preserveScroll: true, onSuccess: () => setConfirmRemove(false) });
    };

    const previewUrl = useMemo(() => {
        const params = new URLSearchParams({
            position_anchor: form.data.position_anchor,
            offset_x_mm: String(form.data.offset_x_mm),
            offset_y_mm: String(form.data.offset_y_mm),
            display_width_mm: String(form.data.display_width_mm),
            rotation_deg: String(form.data.rotation_deg),
        });

        return `/document-stamp/preview?${params.toString()}`;
    }, [form.data.position_anchor, form.data.offset_x_mm, form.data.offset_y_mm, form.data.display_width_mm, form.data.rotation_deg]);

    return (
        <ApplicationShell>
            <Head title="Cachet de l’entreprise" />
            <main className="mx-auto max-w-3xl">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">Cachet de l’entreprise</h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Le cachet numérique utilisé pour marquer vos factures et devis. Ce n’est pas une signature
                            électronique certifiée.
                        </p>
                    </div>
                    <span
                        className={`inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${
                            stamp ? 'bg-success-soft text-success' : 'bg-raised text-ink-muted'
                        }`}
                    >
                        {stamp ? 'Configuré' : 'Non configuré'}
                    </span>
                </div>

                {!canUpdate && (
                    <p className="mt-4 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                        Lecture seule — vous n’avez pas la permission de modifier le cachet de l’entreprise.
                    </p>
                )}

                {form.errors.display_width_mm && (
                    <p className="mt-4 rounded-field border border-danger/30 bg-danger-soft px-3 py-2 text-xs text-danger">
                        {form.errors.display_width_mm}
                    </p>
                )}

                {!stamp && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <p className="text-sm text-ink-muted">
                            Téléversez le cachet numérique de votre entreprise pour pouvoir l’apposer sur vos documents.
                        </p>
                        <p className="mt-1 text-xs text-ink-faint">PNG (idéalement transparent), JPG ou WebP.</p>
                    </section>
                )}

                {stamp && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <h2 className="mb-3 text-sm font-semibold text-ink">Aperçu du cachet</h2>
                        <div className="flex size-28 items-center justify-center overflow-hidden rounded-card border border-line bg-raised">
                            <img
                                key={cacheBust}
                                src={`/document-stamp/image?v=${cacheBust}`}
                                alt="Cachet de l’entreprise"
                                className="max-h-full max-w-full object-contain"
                                style={{ transform: `rotate(${form.data.rotation_deg}deg)` }}
                            />
                        </div>
                    </section>
                )}

                <form onSubmit={submit} className="mt-4 space-y-4">
                    <fieldset disabled={!canUpdate} className="space-y-4">
                        <Section title={stamp ? 'Remplacer l’image' : 'Ajouter un cachet'}>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(e) => form.setData('image', e.target.files?.[0] ?? null)}
                                className="block text-sm"
                            />
                            <p className="text-xs text-ink-faint">PNG (idéalement transparent), JPG ou WebP · 2 Mo max.</p>
                            {form.errors.image && <p className="text-xs text-danger">{form.errors.image}</p>}
                        </Section>

                        <Section title="Position">
                            <div className="grid grid-cols-2 gap-2 sm:w-80">
                                {bounds.anchors.map((anchor) => (
                                    <button
                                        key={anchor}
                                        type="button"
                                        onClick={() => form.setData('position_anchor', anchor)}
                                        className={`rounded-field border px-3 py-2 text-sm transition-soft ${
                                            form.data.position_anchor === anchor
                                                ? 'border-primary bg-primary/10 font-medium text-primary'
                                                : 'border-line-strong text-ink-muted hover:bg-raised'
                                        }`}
                                    >
                                        {ANCHOR_LABELS[anchor]}
                                    </button>
                                ))}
                            </div>

                            <UnitStepper
                                label="Position horizontale"
                                hint="Distance depuis le bord — augmente en s’éloignant du coin vers l’intérieur."
                                value={form.data.offset_x_mm}
                                min={0}
                                max={bounds.max_offset_x_mm}
                                unit="mm"
                                onChange={(v) => form.setData('offset_x_mm', v)}
                            />
                            <UnitStepper
                                label="Position verticale"
                                hint="Distance depuis le bord — augmente en s’éloignant du coin vers l’intérieur."
                                value={form.data.offset_y_mm}
                                min={0}
                                max={bounds.max_offset_y_mm}
                                unit="mm"
                                onChange={(v) => form.setData('offset_y_mm', v)}
                            />
                        </Section>

                        <Section title="Taille">
                            <UnitStepper
                                label="Largeur du cachet"
                                value={form.data.display_width_mm}
                                min={bounds.min_display_width_mm}
                                max={bounds.max_display_width_mm}
                                unit="mm"
                                onChange={(v) => form.setData('display_width_mm', v)}
                            />
                            {form.errors.display_width_mm && <p className="text-xs text-danger">{form.errors.display_width_mm}</p>}
                        </Section>

                        <Section title="Inclinaison du cachet">
                            <UnitStepper
                                label="Inclinaison"
                                value={form.data.rotation_deg}
                                min={bounds.min_rotation_deg}
                                max={bounds.max_rotation_deg}
                                unit="°"
                                onChange={(v) => form.setData('rotation_deg', v)}
                            />
                            <div className="flex items-center gap-2">
                                {[-5, 0, 5].map((quick) => (
                                    <button
                                        key={quick}
                                        type="button"
                                        onClick={() => form.setData('rotation_deg', Math.min(bounds.max_rotation_deg, Math.max(bounds.min_rotation_deg, quick)))}
                                        className="rounded-field border border-line-strong px-2.5 py-1 text-xs text-ink-muted transition-soft hover:bg-raised"
                                    >
                                        {quick > 0 ? `+${quick}°` : `${quick}°`}
                                    </button>
                                ))}
                            </div>
                            {form.errors.rotation_deg && <p className="text-xs text-danger">{form.errors.rotation_deg}</p>}
                            <p className="text-xs text-ink-faint">
                                Ajustez légèrement l’inclinaison pour reproduire l’apparence d’un cachet apposé manuellement.
                            </p>
                        </Section>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="submit" loading={form.processing} loadingText="Enregistrement…">
                                Enregistrer
                            </Button>
                            {stamp && (
                                <a
                                    href={previewUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink transition-soft hover:bg-raised"
                                >
                                    Aperçu sur document
                                </a>
                            )}
                        </div>
                    </fieldset>
                </form>

                {stamp && canUpdate && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <h2 className="text-sm font-semibold text-ink">Désactiver le cachet</h2>
                        <p className="mt-1 text-xs text-ink-muted">
                            Les documents déjà cachetés conservent leur cachet. Seules les nouvelles appositions seront
                            bloquées tant qu’aucun cachet n’est reconfiguré.
                        </p>
                        {!confirmRemove ? (
                            <Button type="button" variant="danger" size="sm" className="mt-3" onClick={() => setConfirmRemove(true)}>
                                Désactiver le cachet
                            </Button>
                        ) : (
                            <div className="mt-3 flex items-center gap-2">
                                <Button type="button" variant="danger" size="sm" loading={form.processing} onClick={remove}>
                                    Confirmer la désactivation
                                </Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmRemove(false)}>
                                    Annuler
                                </Button>
                            </div>
                        )}
                    </section>
                )}
            </main>
        </ApplicationShell>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-card border border-line bg-surface p-5">
            <h2 className="mb-3 text-sm font-semibold text-ink">{title}</h2>
            <div className="space-y-3">{children}</div>
        </section>
    );
}

/**
 * Keeps its own text buffer so typing is never interrupted: an out-of-range
 * or transient value (an empty field, a lone "-", a partial number below the
 * minimum on the way to a larger one) is passed straight through to
 * `onChange` as typed — clamping to [min, max] only happens on blur, when
 * the value is final. This is what stops a user from being "bounced back" to
 * the previous value while still typing a new one. The text buffer resyncs
 * from `value` only when it changes for a reason other than this input's own
 * keystrokes (the +/- buttons, a quick-set button).
 */
function UnitStepper({
    label,
    hint,
    value,
    min,
    max,
    unit,
    step = 1,
    onChange,
}: {
    label: string;
    hint?: string;
    value: number;
    min: number;
    max: number;
    unit: 'mm' | '°';
    step?: number;
    onChange: (value: number) => void;
}) {
    const clamp = (v: number) => Math.min(max, Math.max(min, v));
    const [text, setText] = useState(String(value));

    useEffect(() => {
        setText((current) => (current !== '' && current !== '-' && Number(current) === value ? current : String(value)));
    }, [value]);

    return (
        <div>
            <span className="mb-1 block text-xs font-medium text-ink-muted">{label}</span>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={() => onChange(clamp(value - step))}
                    aria-label={`${label} : diminuer`}
                    className="flex size-8 items-center justify-center rounded-field border border-line-strong text-ink-muted hover:bg-raised"
                >
                    −
                </button>
                <input
                    type="number"
                    value={text}
                    min={min}
                    max={max}
                    step={step}
                    onChange={(e) => {
                        const raw = e.target.value;
                        setText(raw);
                        if (raw === '' || raw === '-') return;
                        const parsed = Number(raw);
                        if (Number.isFinite(parsed)) onChange(parsed);
                    }}
                    onBlur={() => {
                        const parsed = Number(text);
                        const settled = text !== '' && text !== '-' && Number.isFinite(parsed) ? clamp(parsed) : value;
                        setText(String(settled));
                        onChange(settled);
                    }}
                    className="w-20 rounded-field border border-line-strong px-2 py-1.5 text-center text-sm"
                />
                <span className="text-xs text-ink-faint">{unit}</span>
                <button
                    type="button"
                    onClick={() => onChange(clamp(value + step))}
                    aria-label={`${label} : augmenter`}
                    className="flex size-8 items-center justify-center rounded-field border border-line-strong text-ink-muted hover:bg-raised"
                >
                    +
                </button>
            </div>
            {hint && <p className="mt-1 text-xs text-ink-faint">{hint}</p>}
        </div>
    );
}
