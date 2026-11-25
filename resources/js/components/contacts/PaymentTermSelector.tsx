import { SelectField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import type { PaymentTermOption } from './types';

/** Reusable organization payment term (descriptive only: it does not change any due date in this phase). */
export default function PaymentTermSelector({
    terms,
    value,
    onChange,
    label,
    disabled,
    hint,
}: {
    terms: PaymentTermOption[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    disabled?: boolean;
    hint?: string;
}) {
    const { t } = useI18n();

    return (
        <SelectField label={label} hint={hint ?? t('cts.termHelp')} value={value} onChange={(event) => onChange(event.target.value)} disabled={disabled}>
            <option value="">{t('cts.termNone')}</option>
            {terms.map((term) => (
                <option key={term.id} value={String(term.id)}>
                    {term.days === 0 ? t('cts.termCash') : term.name}
                </option>
            ))}
        </SelectField>
    );
}
