import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import type { NotificationPreferences } from '@/types/app';
import { Head, useForm } from '@inertiajs/react';

const labels: Record<string, string> = { system: 'Système', security: 'Sécurité', woocommerce: 'WooCommerce', backup: 'Sauvegardes', inventory: 'Stock', finance: 'Finance', sales: 'Ventes', documents: 'Documents', account: 'Compte' };

export default function NotificationPreferencePage({ preferences, categories, mandatoryCriticalCategories }: { preferences: NotificationPreferences; categories: string[]; mandatoryCriticalCategories: string[] }) {
    const form = useForm(preferences);
    const toggleCategory = (category: string, enabled: boolean) => form.setData('disabled_categories', enabled ? form.data.disabled_categories.filter((item) => item !== category) : [...new Set([...form.data.disabled_categories, category])]);
    return <ApplicationShell><Head title="Préférences de notifications" /><PageHeader title="Notifications" description="Choisissez les alertes visibles et le comportement sonore de votre compte." />
        <form onSubmit={(event) => { event.preventDefault(); form.put('/account/notifications'); }} className="space-y-6">
            <section className="rounded-card border border-line bg-surface p-5 shadow-soft"><h2 className="font-semibold text-ink">Son</h2><label className="mt-4 flex items-center gap-3 text-sm"><input type="checkbox" checked={form.data.sound_enabled} onChange={(event) => form.setData('sound_enabled', event.target.checked)} />Activer le son des nouvelles notifications</label><label className="mt-5 block text-sm font-medium text-ink">Volume · {Math.round(form.data.sound_volume * 100)} %<input type="range" min="0" max="1" step="0.05" disabled={!form.data.sound_enabled} value={form.data.sound_volume} onChange={(event) => form.setData('sound_volume', Number(event.target.value))} className="mt-2 block w-full max-w-md" /></label><p className="mt-3 text-xs text-ink-muted">Le son est désactivé par défaut et peut être bloqué par le navigateur tant que vous n’avez pas interagi avec la page.</p></section>
            <section className="rounded-card border border-line bg-surface p-5 shadow-soft"><h2 className="font-semibold text-ink">Catégories dans l’application</h2><div className="mt-4 grid gap-3 sm:grid-cols-2">{categories.map((category) => { const enabled = !form.data.disabled_categories.includes(category); return <label key={category} className="flex items-center justify-between rounded-field border border-line px-4 py-3 text-sm"><span><span className="font-medium text-ink">{labels[category] ?? category}</span>{mandatoryCriticalCategories.includes(category) && <span className="mt-0.5 block text-xs text-ink-muted">Les alertes critiques restent toujours visibles.</span>}</span><input type="checkbox" checked={enabled} onChange={(event) => toggleCategory(category, event.target.checked)} /></label>; })}</div></section>
            <button type="submit" disabled={form.processing} className="rounded-field bg-primary px-5 py-2.5 text-sm font-semibold text-primary-fg disabled:opacity-50">{form.processing ? 'Enregistrement…' : 'Enregistrer'}</button>
        </form>
    </ApplicationShell>;
}
