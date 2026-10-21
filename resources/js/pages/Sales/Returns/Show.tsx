import { Button } from "@/components/ui/Button";
import SalesLayout from "@/layouts/SalesLayout";
import { useI18n } from "@/i18n";
import { formatDate, formatMoney, formatQuantity } from "@/utils/format";
import { Head, Link, router, useForm } from "@inertiajs/react";

type PaymentOption = {
    id: number;
    payment_number: string;
    method: string;
    allocated_amount: string;
    refundable_amount: string;
    suggested_amount: string;
    financial_account: { name: string; code: string; type: string } | null;
};

type Props = {
    customerReturn: any;
    creditSummary: { issued: string };
    refundSummary: { refunded: string; remaining: string };
    payments: PaymentOption[];
    can: { receive: boolean; cancel: boolean; refund: boolean };
};

export default function ReturnShow({
    customerReturn: item,
    creditSummary,
    refundSummary,
    payments,
    can,
}: Props) {
    const { t } = useI18n();
    const cancel = useForm({ reason: "" });
    const refund = useForm({
        payment_id: payments[0]?.id ?? 0,
        amount: payments[0]?.suggested_amount ?? refundSummary.remaining,
        reason: "",
        client_operation_id: crypto.randomUUID(),
    });

    const selectPayment = (paymentId: number) => {
        const payment = payments.find(
            (candidate) => candidate.id === paymentId,
        );
        refund.setData((data) => ({
            ...data,
            payment_id: paymentId,
            amount: payment?.suggested_amount ?? "",
        }));
    };

    return (
        <SalesLayout>
            <Head title={item.return_number} />
            <div className="mx-auto max-w-5xl space-y-6">
                <header className="flex flex-wrap justify-between gap-3">
                    <div>
                        <Link
                            href="/sales/returns"
                            className="text-sm text-ink-muted"
                        >
                            ← {t("returns.title")}
                        </Link>
                        <h1 className="text-2xl font-semibold">
                            {item.return_number}
                        </h1>
                        <p className="text-sm text-ink-muted">
                            <Link
                                href={`/sales/orders/${item.sales_order.id}`}
                                className="hover:underline"
                            >
                                {t("returns.order")}{" "}
                                <bdi>{item.sales_order.order_number}</bdi>
                            </Link>
                            {" · "}
                            {item.sales_order.customer_name ||
                                t("common.counterCustomer")}
                        </p>
                    </div>
                    <span className="rounded-full bg-raised px-3 py-1 text-sm uppercase">
                        {item.status}
                    </span>
                </header>

                <section className="grid gap-3 rounded-card border border-line bg-surface p-5 sm:grid-cols-4">
                    <Summary
                        label={t("returns.returnedAmount")}
                        value={formatMoney(
                            item.total_incl_tax,
                            item.currency_code,
                        )}
                    />
                    <Summary
                        label={t("returns.issuedCredit")}
                        value={formatMoney(
                            creditSummary.issued,
                            item.currency_code,
                        )}
                    />
                    <Summary
                        label={t("returns.refunded")}
                        value={formatMoney(
                            refundSummary.refunded,
                            item.currency_code,
                        )}
                    />
                    <Summary
                        label={t("returns.remainingRefund")}
                        value={formatMoney(
                            refundSummary.remaining,
                            item.currency_code,
                        )}
                    />
                    <p className="sm:col-span-4 text-sm text-ink-muted">
                        {t("returns.disposition")} :{" "}
                        {item.disposition === "restock"
                            ? t("returns.restockDisposition")
                            : t("returns.damagedDisposition")}{" "}
                        · {t("returns.warehouse")} : {item.warehouse.name}
                    </p>
                </section>

                <section className="rounded-card border border-line bg-surface p-5">
                    <h2 className="font-semibold">
                        {t("returns.returnedItems")}
                    </h2>
                    {item.lines.map((line: any) => (
                        <div
                            key={line.id}
                            className="mt-2 flex justify-between border-t border-line pt-2 text-sm"
                        >
                            <span>
                                {line.reference || line.sku} ·{" "}
                                {line.product_name}
                                {line.variant_name
                                    ? ` — ${line.variant_name}`
                                    : ""}
                            </span>
                            <span>
                                {formatQuantity(line.quantity)} ·{" "}
                                {formatMoney(
                                    line.total_incl_tax,
                                    item.currency_code,
                                )}
                            </span>
                        </div>
                    ))}
                </section>

                {item.credit_notes.length > 0 && (
                    <section className="rounded-card border border-line bg-surface p-5">
                        <h2 className="font-semibold">
                            {t("returns.accountingCredits")}
                        </h2>
                        {item.credit_notes.map((note: any) => (
                            <Link
                                key={note.id}
                                href={`/credit-notes/${note.id}`}
                                className="mt-2 flex justify-between border-t border-line pt-2"
                            >
                                <span>
                                    <bdi>
                                        {note.credit_note_number ||
                                            t("common.draft")}
                                    </bdi>{" "}
                                    · {t("creditNotes.invoice")}{" "}
                                    <bdi>
                                        {note.invoice.invoice_number}
                                        {note.invoice.version > 1
                                            ? ` V${note.invoice.version}`
                                            : ""}
                                    </bdi>
                                </span>
                                <span>
                                    -
                                    {formatMoney(
                                        note.total_incl_tax,
                                        item.currency_code,
                                    )}
                                </span>
                            </Link>
                        ))}
                    </section>
                )}

                {item.refunds.length > 0 && (
                    <section className="rounded-card border border-line bg-surface p-5">
                        <h2 className="font-semibold">
                            {t("returns.financialRefunds")}
                        </h2>
                        {item.refunds.map((entry: any) => (
                            <div
                                key={entry.id}
                                className="mt-2 flex justify-between border-t border-line pt-2 text-sm"
                            >
                                <span>
                                    <bdi>{entry.refund_number}</bdi> ·{" "}
                                    {formatDate(entry.refund_date)} ·{" "}
                                    {t("common.payment")}{" "}
                                    <bdi>{entry.payment.payment_number}</bdi>
                                    <span className="block text-xs text-ink-muted">
                                        {entry.reason}
                                    </span>
                                </span>
                                <span>
                                    -
                                    {formatMoney(
                                        entry.amount,
                                        item.currency_code,
                                    )}
                                </span>
                            </div>
                        ))}
                    </section>
                )}

                {can.receive && (
                    <Button
                        onClick={() =>
                            router.post(`/sales/returns/${item.id}/receive`)
                        }
                    >
                        {t("returns.receive")}
                    </Button>
                )}

                {can.refund &&
                    Number(refundSummary.remaining) > 0 &&
                    payments.length > 0 && (
                        <section className="space-y-3 rounded-card border border-line bg-surface p-5">
                            <div>
                                <h2 className="font-semibold">
                                    {t("returns.refundCustomer")}
                                </h2>
                                <p className="text-xs text-ink-muted">
                                    {t("returns.refundHelp")}
                                </p>
                            </div>
                            <label className="block text-sm">
                                <span className="mb-1 block text-ink-muted">
                                    {t("returns.sourcePayment")}
                                </span>
                                <select
                                    value={refund.data.payment_id}
                                    onChange={(event) =>
                                        selectPayment(
                                            Number(event.target.value),
                                        )
                                    }
                                    className="w-full rounded-field border p-2"
                                >
                                    {payments.map((payment) => (
                                        <option
                                            key={payment.id}
                                            value={payment.id}
                                        >
                                            {payment.payment_number} ·{" "}
                                            {payment.method} ·{" "}
                                            {payment.financial_account
                                                ? `${payment.financial_account.code} ${payment.financial_account.name}`
                                                : t(
                                                      "returns.accountUnavailable",
                                                  )}{" "}
                                            · {t("products.available")}{" "}
                                            {formatMoney(
                                                payment.refundable_amount,
                                                item.currency_code,
                                            )}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm">
                                <span className="mb-1 block text-ink-muted">
                                    {t("returns.refundedAmount")}
                                </span>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    value={refund.data.amount}
                                    onChange={(event) =>
                                        refund.setData(
                                            "amount",
                                            event.target.value,
                                        )
                                    }
                                    className="w-full rounded-field border p-2"
                                />
                            </label>
                            <textarea
                                required
                                value={refund.data.reason}
                                onChange={(event) =>
                                    refund.setData("reason", event.target.value)
                                }
                                placeholder={t("returns.refundReason")}
                                className="w-full rounded-field border p-2"
                            />
                            {Object.values(refund.errors).map(
                                (error) =>
                                    error && (
                                        <p
                                            key={error}
                                            className="text-sm text-danger"
                                        >
                                            {error}
                                        </p>
                                    ),
                            )}
                            <Button
                                loading={refund.processing}
                                onClick={() =>
                                    refund.post(
                                        `/sales/returns/${item.id}/refunds`,
                                    )
                                }
                            >
                                {t("returns.saveRefund")}
                            </Button>
                        </section>
                    )}

                {can.cancel && (
                    <section className="rounded-card border border-danger/30 p-5">
                        <textarea
                            value={cancel.data.reason}
                            onChange={(event) =>
                                cancel.setData("reason", event.target.value)
                            }
                            placeholder={t("returns.cancelReason")}
                            className="w-full rounded-field border p-2"
                        />
                        <Button
                            variant="danger"
                            loading={cancel.processing}
                            onClick={() =>
                                cancel.post(`/sales/returns/${item.id}/cancel`)
                            }
                            className="mt-2"
                        >
                            {t("returns.cancelDraft")}
                        </Button>
                    </section>
                )}
            </div>
        </SalesLayout>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <small className="text-ink-muted">{label}</small>
            <strong className="block">{value}</strong>
        </div>
    );
}
