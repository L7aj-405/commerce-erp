import { ButtonLink } from "@/components/ui/Button";
import PageHeader from "@/components/ui/PageHeader";
import Pagination from "@/components/ui/Pagination";
import { useI18n } from "@/i18n";
import ApplicationShell from "@/layouts/ApplicationShell";
import { formatDate, formatMoney } from "@/utils/format";
import { Head } from "@inertiajs/react";

type Row = {
    id: number;
    number: string;
    due_date: string;
    amount: string;
    paid: string;
    remaining: string;
    cancelled: boolean;
    instrument_count: number;
};
type Props = {
    plan: {
        number: string;
        supplier: { name: string };
        reference: string | null;
        total: string;
        currency_code: string;
        notes: string | null;
        procurement: { procurement_number: string } | null;
        obligations: {
            data: Row[];
            links: { url: string | null; label: string; active: boolean }[];
        };
    };
};

export default function SupplierInstallmentShow({ plan }: Props) {
    const { t } = useI18n();
    const columns = [
        "due",
        "obligation",
        "planned",
        "paid",
        "remaining",
        "instruments",
    ] as const;
    return (
        <ApplicationShell wide>
            <Head title={plan.number} />
            <PageHeader
                title={plan.number}
                description={`${plan.supplier.name} · ${plan.reference ?? t("products.noReference")} · ${t("common.total")} ${formatMoney(plan.total, plan.currency_code)}`}
                actions={
                    <ButtonLink
                        href="/finance/situation-fin-de-mois/echeanciers-fournisseurs"
                        variant="secondary"
                    >
                        {t("supplierInstallments.back")}
                    </ButtonLink>
                }
            />
            <section className="overflow-hidden rounded-card border border-line bg-surface">
                <table className="w-full text-sm">
                    <thead className="bg-raised text-start text-xs uppercase text-ink-muted">
                        <tr>
                            {columns.map((key) => (
                                <th key={key} className="px-4 py-3">
                                    {t(
                                        `supplierInstallments.detail.${key}` as Parameters<
                                            typeof t
                                        >[0],
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {plan.obligations.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3">
                                    {formatDate(row.due_date)}
                                </td>
                                <td className="px-4 py-3 font-medium">
                                    <bdi>{row.number}</bdi>
                                </td>
                                <td className="px-4 py-3 text-end">
                                    {formatMoney(
                                        row.amount,
                                        plan.currency_code,
                                    )}
                                </td>
                                <td className="px-4 py-3 text-end">
                                    {formatMoney(row.paid, plan.currency_code)}
                                </td>
                                <td className="px-4 py-3 text-end font-semibold">
                                    {formatMoney(
                                        row.remaining,
                                        plan.currency_code,
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    {row.instrument_count}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
            <Pagination links={plan.obligations.links} />
        </ApplicationShell>
    );
}
