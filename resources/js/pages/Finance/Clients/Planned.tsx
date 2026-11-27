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
 * Placeholder shell for the one Finance clients area that is still announced but NOT built: future staff finance beyond the existing
 * commissions screens. There is deliberately no data, no form and no action here. (Credit control and incoming instruments, formerly
 * shells, are implemented in Credit.tsx and Instruments.tsx.)
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
