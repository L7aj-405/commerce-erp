import SandboxReturnsBadge from "@/components/SandboxReturnsBadge";
import { Button, ButtonLink } from "@/components/ui/Button";
import { Dialog } from "@/components/ui/Dialog";
import DocBadge from "@/components/ui/DocBadge";
import type { BadgeTone } from "@/utils/labels";
import { useState } from "react";
import SalesLayout from "@/layouts/SalesLayout";
import { useI18n } from "@/i18n";
import { formatDate, formatMoney, formatQuantity } from "@/utils/format";
import { Head, Link, useForm } from "@inertiajs/react";

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
    const receipt = useForm({});
    const [confirmReceive, setConfirmReceive] = useState(false);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const statusTone: Record<string, BadgeTone> = { draft: "warning", received: "positive", cancelled: "danger" };
    const statusLabel: Record<string, string> = { draft: t("returns.draft"), received: t("returns.received"), cancelled: t("returns.cancelled") };
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
            <div className="space-y-6">
                <header className="flex flex-wrap justify-between gap-3">
                    <div>
                        <ButtonLink href="/sales/returns" variant="ghost" size="sm" className="-ms-2 mb-1">
                            <svg aria-hidden="true" className="directional-icon rtl:rotate-180" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 6 8 12l6 6" /></svg>
                            {t("returns.title")}
                        </ButtonLink>
                        <SandboxReturnsBadge kind="return" />
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="me-1 min-w-0 text-2xl font-semibold tracking-[-0.02em] text-ink [overflow-wrap:anywhere] sm:text-page">
                                <bdi>{item.return_number}</bdi>
                            </h1>
                            <DocBadge tone={statusTone[item.status] ?? "neutral"}>
                                {statusLabel[item.status] ?? String(item.status).replaceAll("_", " ")}
                            </DocBadge>
                        </div>
                        <p className="text-sm text-ink-muted">
                            <Link
                                href={`/sales/orders/${item.sales_order.id}`}
                                className="font-medium text-ink underline-offset-2 hover:text-accent hover:underline"
                            >
                                {t("returns.order")}{" "}
                                <bdi>{item.sales_order.order_number}</bdi>
                            </Link>
                            {" · "}
                            {item.sales_order.customer_name ||
                                t("common.counterCustomer")}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {can.cancel && (
                            <Button variant="danger" onClick={() => setConfirmCancel(true)}>
                                {t("returns.cancelDraft")}
                            </Button>
                        )}
                        {can.receive && (
                            <Button onClick={() => setConfirmReceive(true)}>
                                {t("returns.receive")}
                            </Button>
                        )}
                    </div>
                </header>

                <section className="surface-card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
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

                <section className="surface-card p-5">
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
                    <section className="surface-card p-5">
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
                    <section className="surface-card p-5">
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

                <Dialog
                    open={confirmReceive && can.receive}
                    onClose={() => setConfirmReceive(false)}
                    dismissible={!receipt.processing}
                    size="sm"
                    title={t("returns.receive")}
                    description={t("returns.receiveConfirmHelp")}
                >
                    <div className="space-y-4 p-4 sm:p-6">
                        {Object.values(receipt.errors as Record<string, string | undefined>).map((error) => error && <p key={error} className="text-sm text-danger">{error}</p>)}
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setConfirmReceive(false)} disabled={receipt.processing}>
                                {t("common.back")}
                            </Button>
                            <Button
                                type="button"
                                data-autofocus
                                loading={receipt.processing}
                                onClick={() => receipt.post(`/sales/returns/${item.id}/receive`, { onSuccess: () => setConfirmReceive(false) })}
                            >
                                {t("returns.receive")}
                            </Button>
                        </div>
                    </div>
                </Dialog>

                {can.refund &&
                    Number(refundSummary.remaining) > 0 &&
                    payments.length > 0 && (
                        <section className="space-y-3 surface-card p-5">
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
                                    className="control-select w-full"
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
                                    className="control-input w-full"
                                />
                            </label>
                            <textarea
                                required
                                value={refund.data.reason}
                                onChange={(event) =>
                                    refund.setData("reason", event.target.value)
                                }
                                placeholder={t("returns.refundReason")}
                                className="control-input w-full py-2"
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

                <Dialog
                    open={confirmCancel && can.cancel}
                    onClose={() => setConfirmCancel(false)}
                    dismissible={!cancel.processing}
                    danger
                    size="sm"
                    title={t("returns.cancelDraft")}
                    description={item.return_number}
                >
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            cancel.post(`/sales/returns/${item.id}/cancel`, { onSuccess: () => setConfirmCancel(false) });
                        }}
                        className="space-y-4 p-4 sm:p-6"
                    >
                        <label htmlFor="return-cancel-reason" className="block text-sm font-medium text-ink">
                            {t("returns.cancelReason")}
                        </label>
                        <textarea
                            id="return-cancel-reason"
                            data-autofocus
                            value={cancel.data.reason}
                            onChange={(event) => cancel.setData("reason", event.target.value)}
                            className="control-base min-h-24 px-3 py-2 text-sm"
                        />
                        {Object.values(cancel.errors).map((error) => error && <p key={error} className="text-sm text-danger">{error}</p>)}
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setConfirmCancel(false)} disabled={cancel.processing}>
                                {t("common.back")}
                            </Button>
                            <Button type="submit" variant="danger-solid" loading={cancel.processing}>
                                {t("returns.cancelDraft")}
                            </Button>
                        </div>
                    </form>
                </Dialog>
            </div>
        </SalesLayout>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-[13px] text-ink-muted">{label}</p>
            <p className="metric-value mt-0.5 text-lg font-semibold text-ink" dir="ltr">{value}</p>
        </div>
    );
}
