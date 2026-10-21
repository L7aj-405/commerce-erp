import { Button } from "@/components/ui/Button";
import SalesLayout from "@/layouts/SalesLayout";
import { useI18n } from "@/i18n";
import { formatMoney, formatQuantity } from "@/utils/format";
import { Head, Link, router, useForm } from "@inertiajs/react";

type Props = {
    exchange: any;
    replacementItems: any[];
    settlementSummary: {
        target: string;
        settled: string;
        remaining: string;
        direction: "payment" | "refund" | "zero";
    };
    payments: any[];
    financialAccounts: any[];
    can: { process: boolean; refund: boolean };
};
const today = () => new Date().toISOString().slice(0, 10);

export default function ShowExchange({
    exchange,
    replacementItems,
    settlementSummary,
    payments,
    financialAccounts,
    can,
}: Props) {
    const { t } = useI18n();
    const difference = Number(exchange.difference_amount);
    const paymentForm = useForm({
        client_operation_id: crypto.randomUUID(),
        payments: [
            {
                method: "cash",
                financial_account_id: "",
                amount: settlementSummary.remaining,
                payment_date: today(),
                reference: `Échange ${exchange.exchange_number}`,
            },
        ],
    });
    const refundForm = useForm({
        client_operation_id: crypto.randomUUID(),
        payment_id: "",
        amount: settlementSummary.remaining,
        reason: `Échange ${exchange.exchange_number}`,
    });
    const cancelForm = useForm({ reason: "" });
    const currency = exchange.sales_order.currency_code;

    return (
        <SalesLayout>
            <Head title={exchange.exchange_number} />
            <div className="mx-auto max-w-5xl space-y-6">
                <header>
                    <Link
                        href={`/sales/orders/${exchange.sales_order_id}`}
                        className="text-sm text-ink-muted"
                    >
                        ← {exchange.sales_order.order_number}
                    </Link>
                    <h1 className="mt-1 text-2xl font-semibold">
                        {exchange.exchange_number}
                    </h1>
                    <p className="text-sm text-ink-muted">
                        {exchange.status} · {exchange.settlement_status}
                    </p>
                </header>
                <section className="rounded-card border border-line bg-surface p-5">
                    <div className="grid gap-4 sm:grid-cols-4">
                        <div>
                            <p className="text-sm text-ink-muted">
                                {t("exchanges.returnedValue")}
                            </p>
                            <p className="text-xl font-semibold">
                                {formatMoney(exchange.returned_total, currency)}
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-ink-muted">
                                {t("exchanges.newItems")}
                            </p>
                            <p className="text-xl font-semibold">
                                {formatMoney(
                                    exchange.new_items_total,
                                    currency,
                                )}
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-ink-muted">
                                {difference > 0
                                    ? t("exchanges.differenceDue")
                                    : difference < 0
                                      ? t("exchanges.toRefund")
                                      : t("exchanges.balance")}
                            </p>
                            <p className="text-xl font-bold text-primary">
                                {formatMoney(Math.abs(difference), currency)}
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-ink-muted">
                                {t("exchanges.remaining")}
                            </p>
                            <p className="text-xl font-bold text-primary">
                                {formatMoney(
                                    settlementSummary.remaining,
                                    currency,
                                )}
                            </p>
                        </div>
                    </div>
                </section>
                <div className="grid gap-6 md:grid-cols-2">
                    <section className="rounded-card border border-line bg-surface p-5">
                        <h2 className="font-semibold">
                            {t("returns.returnedItems")}
                        </h2>
                        {exchange.customer_return.lines.map((line: any) => (
                            <div
                                key={line.id}
                                className="flex justify-between border-b border-line py-3 text-sm"
                            >
                                <span>
                                    {line.product_name} ×{" "}
                                    {formatQuantity(line.quantity)}
                                </span>
                                <span>
                                    {formatMoney(line.total_incl_tax, currency)}
                                </span>
                            </div>
                        ))}
                    </section>
                    <section className="rounded-card border border-line bg-surface p-5">
                        <h2 className="font-semibold">
                            {t("exchanges.newItems")}
                        </h2>
                        {replacementItems.map((line: any) => (
                            <div
                                key={line.product_variant_id}
                                className="border-b border-line py-3 text-sm"
                            >
                                <p>
                                    {line.product_name}
                                    {line.variant_name
                                        ? ` — ${line.variant_name}`
                                        : ""}{" "}
                                    × {formatQuantity(line.quantity)}
                                </p>
                                <p className="text-xs text-ink-muted">
                                    {line.reference ??
                                        t("products.noReference")}
                                </p>
                            </div>
                        ))}
                    </section>
                </div>
                {can.process &&
                    exchange.status === "awaiting_return_receipt" && (
                        <Button
                            onClick={() =>
                                router.post(
                                    `/sales/exchanges/${exchange.id}/receive`,
                                )
                            }
                        >
                            {t("exchanges.receivePhysical")}
                        </Button>
                    )}
                {can.process && exchange.status === "awaiting_replacement" && (
                    <Button
                        onClick={() =>
                            router.post(
                                `/sales/exchanges/${exchange.id}/fulfill`,
                            )
                        }
                    >
                        {t("exchanges.handOverNew")}
                    </Button>
                )}
                {can.process &&
                    exchange.status === "awaiting_settlement" &&
                    difference > 0 && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="font-semibold">
                                {t("exchanges.additionalPayment")}
                            </h2>
                            <div className="mt-3 grid gap-3 sm:grid-cols-3">
                                <select
                                    value={paymentForm.data.payments[0].method}
                                    onChange={(e) =>
                                        paymentForm.setData("payments", [
                                            {
                                                ...paymentForm.data.payments[0],
                                                method: e.target.value,
                                            },
                                        ])
                                    }
                                    className="rounded-field border border-line-strong px-3 py-2"
                                >
                                    <option value="cash">
                                        {t("payments.method.cash")}
                                    </option>
                                    <option value="card">
                                        {t("payments.method.card")}
                                    </option>
                                    <option value="bank_transfer">
                                        {t("payments.method.bank_transfer")}
                                    </option>
                                    <option value="cheque">
                                        {t("payments.method.cheque")}
                                    </option>
                                </select>
                                <select
                                    value={
                                        paymentForm.data.payments[0]
                                            .financial_account_id
                                    }
                                    onChange={(e) =>
                                        paymentForm.setData("payments", [
                                            {
                                                ...paymentForm.data.payments[0],
                                                financial_account_id:
                                                    e.target.value,
                                            },
                                        ])
                                    }
                                    className="rounded-field border border-line-strong px-3 py-2"
                                >
                                    <option value="">
                                        {t("exchanges.receivingAccount")}
                                    </option>
                                    {financialAccounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    value={paymentForm.data.payments[0].amount}
                                    onChange={(e) =>
                                        paymentForm.setData("payments", [
                                            {
                                                ...paymentForm.data.payments[0],
                                                amount: e.target.value,
                                            },
                                        ])
                                    }
                                    className="rounded-field border border-line-strong px-3 py-2"
                                />
                            </div>
                            <Button
                                className="mt-3"
                                loading={paymentForm.processing}
                                onClick={() =>
                                    paymentForm.post(
                                        `/sales/exchanges/${exchange.id}/payments`,
                                    )
                                }
                            >
                                {t("payments.record")}
                            </Button>
                        </section>
                    )}
                {can.process &&
                    can.refund &&
                    exchange.status === "awaiting_settlement" &&
                    difference < 0 && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="font-semibold">
                                {t("exchanges.refund")}
                            </h2>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                <select
                                    value={refundForm.data.payment_id}
                                    onChange={(e) =>
                                        refundForm.setData(
                                            "payment_id",
                                            e.target.value,
                                        )
                                    }
                                    className="rounded-field border border-line-strong px-3 py-2"
                                >
                                    <option value="">
                                        {t("exchanges.originalPayment")}
                                    </option>
                                    {payments.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.payment_number} ·{" "}
                                            {formatMoney(p.amount, currency)}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    value={refundForm.data.amount}
                                    onChange={(e) =>
                                        refundForm.setData(
                                            "amount",
                                            e.target.value,
                                        )
                                    }
                                    className="rounded-field border border-line-strong px-3 py-2"
                                />
                            </div>
                            <Button
                                className="mt-3"
                                loading={refundForm.processing}
                                onClick={() =>
                                    refundForm.post(
                                        `/sales/exchanges/${exchange.id}/refunds`,
                                    )
                                }
                            >
                                {t("returns.saveRefund")}
                            </Button>
                        </section>
                    )}
                {can.process &&
                    !["completed", "cancelled"].includes(exchange.status) &&
                    !exchange.sales_order_addendum_id && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="font-semibold">
                                {t("exchanges.stop")}
                            </h2>
                            <input
                                value={cancelForm.data.reason}
                                onChange={(e) =>
                                    cancelForm.setData("reason", e.target.value)
                                }
                                placeholder={t("exchanges.reasonRequired")}
                                className="mt-3 w-full rounded-field border border-line-strong px-3 py-2"
                            />
                            <Button
                                variant="secondary"
                                className="mt-3"
                                onClick={() =>
                                    cancelForm.post(
                                        `/sales/exchanges/${exchange.id}/cancel`,
                                    )
                                }
                            >
                                {t("exchanges.cancel")}
                            </Button>
                        </section>
                    )}
            </div>
        </SalesLayout>
    );
}
