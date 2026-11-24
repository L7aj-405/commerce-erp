import { FinancePlaceholder, SectionTabs, useSection } from '@/components/finance/FinanceClientsKit';
import { Alert } from '@/components/ui/Alert';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head } from '@inertiajs/react';

type PageKey = 'personnel';
type Props = { page: PageKey; organization: { id: number; name: string } };
type Config = { title: TranslationKey; description: TranslationKey; notice?: TranslationKey; sections: { key: string; label: TranslationKey; planned: TranslationKey }[] };

/**
 * Complete page shells for functionality that is announced but NOT built (Phase 1: UI/UX only).
 * There is deliberately no data, no form and no action here:
 *  - credit: future credit limits / exposure / payment terms / overruns / blocked customers / authorizations
 *    (no credit validation engine, no new database field).
 *  - instruments: future incoming customer cheques & bills (portfolio, bank deposits, cashed, rejected, due dates).
 *    Posted cheque payments are NOT proof of bank clearance and supplier (outgoing) instruments are never reused.
 *  - personnel: future staff finance (beyond the existing commissions screens).
 */
const CONFIG: Record<PageKey, Config> = {
    personnel: {
        title: 'fc.staff.title',
        description: 'fc.staff.description',
        sections: [{ key: 'overview', label: 'fc.staff.s.overview', planned: 'fc.staff.p.overview' }],
    },
};

export default function FinanceClientsPlanned({ page, organization }: Props) {
    const { t } = useI18n();
    const config = CONFIG[page];
    const keys = config.sections.map((section) => section.key);
    const active = useSection(keys, keys[0] as string);
    const current = config.sections.find((section) => section.key === active) ?? config.sections[0]!;

    return (
        <ApplicationShell wide>
            <Head title={`${t(config.title)} — Finance`} />
            <PageHeader title={t(config.title)} description={`${organization.name} — ${t(config.description)}`} />
            {config.notice && (
                <div className="mb-4">
                    <Alert tone="info">{t(config.notice)}</Alert>
                </div>
            )}
            {config.sections.length > 1 && <SectionTabs sections={config.sections.map((section) => ({ key: section.key, label: t(section.label) }))} active={active} label={t(config.title)} />}
            <FinancePlaceholder plannedKey={current.planned} />
        </ApplicationShell>
    );
}
