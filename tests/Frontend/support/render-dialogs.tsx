// Bundled by supplier-invoice-error-feedback.test.ts (rolldown) and server-rendered: the repo has no DOM/browser harness.
import { ApproveDialog } from '@/components/finance/supplier-invoices/ApproveDialog';
import { VoidDialog } from '@/components/finance/supplier-invoices/VoidDialog';
import { I18nProvider } from '@/i18n';
import { renderToStaticMarkup } from 'react-dom/server';

type Errors = Record<string, string | undefined>;

export function renderVoid(errors: Errors): string {
    return renderToStaticMarkup(
        <I18nProvider>
            <VoidDialog open onClose={() => {}} onConfirm={() => {}} errors={errors} />
        </I18nProvider>,
    );
}

export function renderApprove(errors: Errors, matchStatus: 'po_matched' | 'unmatched' = 'po_matched'): string {
    return renderToStaticMarkup(
        <I18nProvider>
            <ApproveDialog open onClose={() => {}} onConfirm={() => {}} totalTtc="1200.00" currencyCode="MAD" matchStatus={matchStatus} warnings={[]} errors={errors} />
        </I18nProvider>,
    );
}
