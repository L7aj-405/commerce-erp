import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import FollowUpFormDialog from './FollowUpFormDialog';
import FollowUpList from './FollowUpList';
import type { FollowUpPanelData } from './types';

/** Minimal follow-up section for customer / quotation detail pages (open first, then recent). */
export default function FollowUpPanel({ panel }: { panel: FollowUpPanelData }) {
    const { t } = useI18n();
    const [creating, setCreating] = useState(false);
    if (!panel) return null;

    return (
        <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold text-ink">{t('work.panelTitle')}</h2>
                    <p className="text-xs text-ink-muted">{t('work.panelHelp')}</p>
                </div>
                <div className="flex gap-2">
                    <Link href="/work" className="inline-flex min-h-8 items-center rounded-field px-3 text-xs font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink">{t('work.openMyWork')}</Link>
                    {panel.context.can_create && <Button size="sm" onClick={() => setCreating(true)}>+ {t('work.new')}</Button>}
                </div>
            </div>
            {panel.items.length === 0 ? (
                <p className="rounded-field border border-dashed border-line px-3 py-4 text-center text-sm text-ink-muted">{t('work.panelEmpty')}</p>
            ) : (
                <FollowUpList items={panel.items} context={panel.context} showAssignee={panel.context.can_manage_all} />
            )}
            {creating && <FollowUpFormDialog open onClose={() => setCreating(false)} context={panel.context} prefill={panel.prefill} />}
        </section>
    );
}
