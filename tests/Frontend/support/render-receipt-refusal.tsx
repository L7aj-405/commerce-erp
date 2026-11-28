// Server-rendered by transfer-receipt-refusal.test.ts.
import ReceiptRefusal from '@/components/inventory/ReceiptRefusal';
import { I18nProvider } from '@/i18n';
import { renderToStaticMarkup } from 'react-dom/server';

export function renderReceiptRefusal(errors: Record<string, string>): string {
    return renderToStaticMarkup(
        <I18nProvider>
            <ReceiptRefusal errors={errors} />
        </I18nProvider>,
    );
}
