import PaymentsLayout from "@/layouts/PaymentsLayout";
import { useI18n } from "@/i18n";
import { formatDate, formatDateTime, formatMoney } from "@/utils/format";
import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";

type Payment = {
    id: number;
    payment_number: string;
    method: string;
    status: string;
    amount: string;
    currency_code: string;
    payment_date: string;
    reference: string | null;
    external_reference: string | null;
    notes: string | null;
    reversal_reason: string | null;
    reversed_at: string | null;
    store: { name: string; code: string };
    financial_account: { name: string; code: string; type: string };
    received_by: { name: string };
    reversed_by: { name: string } | null;
    allocations: {
        id: number;
        amount: string;
        sales_order: {
            id: number;
            order_number: string;
            customer_name: string | null;
            customer_company: string | null;
            total_incl_tax: string;
            currency_code: string;
        };
    }[];
    refunds: {
        id: number;
        refund_number: string;
        amount: string;
        refund_date: string;
        reason: string;
        refunded_by: { name: string } | null;
    }[];
};
type Props = { payment: Payment; can: { reverse: boolean } };

export default function PaymentShow({ payment, can }: Props) {
    const { t } = useI18n();
    const reversal = useForm({ reason: "" });
    const reverse = (event: FormEvent) => {
        event.preventDefault();
        reversal.post(`/payments/${payment.id}/reverse`, {
            preserveScroll: true,
        });
    };
    return (
        <PaymentsLayout>
            <Head title={payment.payment_number} />
            <div className="mb-6">
                <Link href="/payments" className="text-sm text-slate-500">
                    ← {t("payments.register")}
                </Link>
                <h2 className="mt-2 text-xl font-semibold">
                    <bdi>{payment.payment_number}</bdi>
                </h2>
            </div>
            <section className="mb-6 grid gap-4 rounded-lg bg-slate-50 p-5 md:grid-cols-4">
                <div>
                    {t("payments.businessDate")}
                    <br />
                    <strong>{formatDate(payment.payment_date)}</strong>
                </div>
                <div>
                    {t("common.status")}
                    <br />
                    <strong>
                        {t(
                            `payments.status.${payment.status}` as Parameters<
                                typeof t
                            >[0],
                        )}
                    </strong>
                </div>
                <div>
                    {t("payments.method")}
                    <br />
                    <strong>
                        {t(
                            `payments.method.${payment.method}` as Parameters<
                                typeof t
                            >[0],
                        )}
                    </strong>
                </div>
                <div>
                    {t("common.amount")}
                    <br />
                    <strong>
                        {formatMoney(payment.amount, payment.currency_code)}
                    </strong>
                </div>
                <div>
                    {t("payments.account")}
                    <br />
                    <strong>
                        {payment.financial_account.code} ·{" "}
                        {payment.financial_account.name}
                    </strong>
                </div>
                <div>
                    {t("common.store")}
                    <br />
                    <strong>
                        {payment.store.code} · {payment.store.name}
                    </strong>
                </div>
                <div>
                    {t("payments.receivedBy")}
                    <br />
                    <strong>{payment.received_by.name}</strong>
                </div>
                <div>
                    {t("common.reference")}
                    <br />
                    <strong>{payment.reference ?? "—"}</strong>
                </div>
            </section>
            <section className="mb-6 rounded-lg border p-5">
                <h3 className="mb-3 font-semibold">
                    {t("payments.allocation")}
                </h3>
                {payment.allocations.map((allocation) => (
                    <div
                        key={allocation.id}
                        className="flex flex-wrap justify-between gap-3"
                    >
                        <div>
                            <Link
                                href={`/sales/orders/${allocation.sales_order.id}`}
                                className="font-medium underline"
                            >
                                <bdi>{allocation.sales_order.order_number}</bdi>
                            </Link>
                            <br />
                            <span className="text-sm text-slate-500">
                                {allocation.sales_order.customer_name ??
                                    allocation.sales_order.customer_company ??
                                    t("common.counterCustomer")}
                            </span>
                        </div>
                        <strong>
                            {formatMoney(
                                allocation.amount,
                                payment.currency_code,
                            )}
                        </strong>
                    </div>
                ))}
            </section>
            {payment.refunds.length > 0 && (
                <section className="mb-6 rounded-lg border border-red-200 bg-red-50 p-5">
                    <h3 className="mb-3 font-semibold text-red-900">
                        {t("payments.refunds")}
                    </h3>
                    {payment.refunds.map((refund) => (
                        <div
                            key={refund.id}
                            className="flex flex-wrap justify-between gap-3 border-t border-red-100 py-2 first:border-0"
                        >
                            <div>
                                <strong>
                                    <bdi>{refund.refund_number}</bdi>
                                </strong>
                                <br />
                                <span className="text-sm text-slate-600">
                                    {formatDate(refund.refund_date)} ·{" "}
                                    {refund.reason} ·{" "}
                                    {refund.refunded_by?.name ??
                                        t("payments.deletedUser")}
                                </span>
                            </div>
                            <strong className="text-red-800">
                                −{" "}
                                {formatMoney(
                                    refund.amount,
                                    payment.currency_code,
                                )}
                            </strong>
                        </div>
                    ))}
                </section>
            )}
            {(payment.external_reference || payment.notes) && (
                <section className="mb-6 rounded-lg border p-5">
                    <h3 className="mb-2 font-semibold">
                        {t("payments.details")}
                    </h3>
                    {payment.external_reference && (
                        <p>
                            {t("payments.externalReference")} :{" "}
                            <bdi>{payment.external_reference}</bdi>
                        </p>
                    )}
                    {payment.notes && (
                        <p className="mt-2 whitespace-pre-wrap">
                            {payment.notes}
                        </p>
                    )}
                </section>
            )}
            {payment.status === "reversed" && (
                <section className="rounded-lg border border-amber-200 bg-amber-50 p-5">
                    <h3 className="font-semibold">{t("payments.reversed")}</h3>
                    <p className="mt-2">{payment.reversal_reason}</p>
                    <p className="mt-1 text-sm text-slate-500">
                        {t("payments.by")}{" "}
                        {payment.reversed_by?.name ?? t("payments.unknown")}
                        {payment.reversed_at
                            ? ` ${t("payments.on")} ${formatDateTime(payment.reversed_at)}`
                            : ""}
                    </p>
                </section>
            )}
            {payment.status === "posted" && can.reverse && (
                <form
                    onSubmit={reverse}
                    className="max-w-xl space-y-3 rounded-lg border border-red-200 p-5"
                >
                    <h3 className="font-semibold text-red-800">
                        {t("payments.reverse")}
                    </h3>
                    <p className="text-sm text-slate-600">
                        {t("payments.reverseHelp")}
                    </p>
                    <textarea
                        required
                        maxLength={2000}
                        value={reversal.data.reason}
                        onChange={(event) =>
                            reversal.setData("reason", event.target.value)
                        }
                        placeholder={t("payments.reverseReason")}
                        className="w-full rounded border px-3 py-2"
                    />
                    {reversal.errors.reason && (
                        <p className="text-sm text-red-600">
                            {reversal.errors.reason}
                        </p>
                    )}
                    <button
                        disabled={reversal.processing}
                        className="rounded border border-red-300 px-4 py-2 text-red-800 disabled:opacity-50"
                    >
                        {t("payments.reverse")}
                    </button>
                </form>
            )}
        </PaymentsLayout>
    );
}
