import { Button } from "@/components/ui/Button";
import { useDebouncedValue } from "@/components/pos/useDebouncedValue";
import SalesLayout from "@/layouts/SalesLayout";
import { useI18n } from "@/i18n";
import { formatMoney, formatQuantity } from "@/utils/format";
import { Head, Link, useForm } from "@inertiajs/react";
import { useEffect, useState } from "react";

type ReturnLine = {
    id: number;
    product_variant_id: number;
    product_name: string;
    variant_name: string | null;
    reference: string | null;
    sku: string | null;
    quantity: string;
    already_returned: string;
    returnable: string;
    total_incl_tax: string;
};
type Variant = {
    id: number;
    product_name: string;
    variant_name: string | null;
    reference: string | null;
    sku: string | null;
    unit_price_incl_tax: string | null;
    local_stock_available: string;
    config_missing: boolean;
};
type Props = {
    order: {
        id: number;
        order_number: string;
        customer_name: string | null;
        currency_code: string;
    };
    policy: {
        within_policy: boolean;
        deadline: string | null;
        enabled: boolean;
        default_disposition: string;
    };
    lines: ReturnLine[];
    canOverride: boolean;
    searchUrl: string;
    submitUrl: string;
};

export default function CreateExchange({
    order,
    policy,
    lines,
    canOverride,
    searchUrl,
    submitUrl,
}: Props) {
    const { t } = useI18n();
    const [returnQty, setReturnQty] = useState<Record<number, string>>({});
    const [query, setQuery] = useState("");
    const debounced = useDebouncedValue(query.trim(), 250);
    const [results, setResults] = useState<Variant[]>([]);
    const [cart, setCart] = useState<(Variant & { quantity: string })[]>([]);
    const [searching, setSearching] = useState(false);
    const form = useForm({
        client_operation_id: crypto.randomUUID(),
        reason: "",
        disposition: policy.default_disposition || "restock",
        override_policy: false,
        override_reason: "",
        returned_items: [] as {
            sales_order_line_id: number;
            quantity: string;
        }[],
        replacement_items: [] as {
            product_variant_id: number;
            quantity: string;
        }[],
    });

    useEffect(() => {
        if (!debounced) {
            setResults([]);
            setSearching(false);
            return;
        }
        const controller = new AbortController();
        setSearching(true);
        void fetch(`${searchUrl}?search=${encodeURIComponent(debounced)}`, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            signal: controller.signal,
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(),
            )
            .then((payload: { data: Variant[] }) =>
                setResults(payload.data ?? []),
            )
            .catch(() => {
                if (!controller.signal.aborted) setResults([]);
            })
            .finally(() => {
                if (!controller.signal.aborted) setSearching(false);
            });
        return () => controller.abort();
    }, [debounced, searchUrl]);

    const returnedTotal = lines.reduce(
        (sum, line) =>
            sum +
            (Number(line.total_incl_tax) / Number(line.quantity)) *
                Number(returnQty[line.id] || 0),
        0,
    );
    const newTotal = cart.reduce(
        (sum, line) =>
            sum +
            Number(line.unit_price_incl_tax || 0) * Number(line.quantity || 0),
        0,
    );
    const difference = newTotal - returnedTotal;
    const plannedRestock = (variantId: number) =>
        form.data.disposition === "restock"
            ? lines
                  .filter((line) => line.product_variant_id === variantId)
                  .reduce(
                      (sum, line) => sum + Number(returnQty[line.id] || 0),
                      0,
                  )
            : 0;
    const submit = () => {
        form.transform((data) => ({
            ...data,
            returned_items: lines
                .filter((line) => Number(returnQty[line.id] || 0) > 0)
                .map((line) => ({
                    sales_order_line_id: line.id,
                    quantity: returnQty[line.id],
                })),
            replacement_items: cart.map((line) => ({
                product_variant_id: line.id,
                quantity: line.quantity,
            })),
        }));
        form.post(submitUrl);
    };

    return (
        <SalesLayout>
            <Head title={`${t("exchanges.exchange")} ${order.order_number}`} />
            <div className="mx-auto max-w-6xl space-y-6">
                <header>
                    <Link
                        href={`/sales/orders/${order.id}`}
                        className="text-sm text-ink-muted"
                    >
                        ← <bdi>{order.order_number}</bdi>
                    </Link>
                    <h1 className="mt-1 text-2xl font-semibold text-ink">
                        {t("exchanges.exchangeItems")}
                    </h1>
                    <p className="text-sm text-ink-muted">
                        {t("exchanges.physicalReturnHelp")}
                    </p>
                </header>
                {!policy.enabled && (
                    <p className="rounded-field border border-danger/30 bg-danger-soft p-3 text-sm text-danger">
                        {t("returns.disabled")}
                    </p>
                )}
                {!policy.within_policy && !canOverride && (
                    <p className="rounded-field border border-danger/30 bg-danger-soft p-3 text-sm text-danger">
                        {t("returns.deadlineExceeded")}
                    </p>
                )}

                <section className="rounded-card border border-line bg-surface p-5">
                    <h2 className="font-semibold text-ink">
                        1 — {t("returns.returnedItems")}
                    </h2>
                    <div className="mt-3 divide-y divide-line">
                        {lines.map((line) => (
                            <div
                                key={line.id}
                                className="grid items-center gap-3 py-3 sm:grid-cols-[1fr_repeat(3,100px)]"
                            >
                                <div>
                                    <p className="font-medium text-ink">
                                        {line.product_name}
                                        {line.variant_name
                                            ? ` — ${line.variant_name}`
                                            : ""}
                                    </p>
                                    <p className="text-xs text-ink-muted">
                                        {line.reference ??
                                            line.sku ??
                                            t("products.noReference")}
                                    </p>
                                </div>
                                <div className="text-xs text-ink-muted">
                                    {t("returns.column.purchased")}
                                    <br />
                                    <strong>
                                        {formatQuantity(line.quantity)}
                                    </strong>
                                </div>
                                <div className="text-xs text-ink-muted">
                                    {t("returns.column.returnable")}
                                    <br />
                                    <strong>
                                        {formatQuantity(line.returnable)}
                                    </strong>
                                </div>
                                <label className="text-xs text-ink-muted">
                                    {t("exchanges.quantityToReturn")}
                                    <input
                                        value={returnQty[line.id] ?? ""}
                                        onChange={(e) =>
                                            setReturnQty({
                                                ...returnQty,
                                                [line.id]: e.target.value,
                                            })
                                        }
                                        inputMode="decimal"
                                        className="mt-1 w-full rounded-field border border-line-strong px-2 py-2"
                                    />
                                </label>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="rounded-card border border-line bg-surface p-5">
                    <h2 className="font-semibold text-ink">
                        2 — {t("exchanges.newItems")}
                    </h2>
                    <input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t("exchanges.searchPlaceholder")}
                        className="mt-3 w-full rounded-field border border-line-strong px-3 py-2"
                    />
                    <div className="mt-2 divide-y divide-line rounded-field border border-line">
                        {searching && (
                            <p className="p-3 text-sm text-ink-muted">
                                {t("common.searching")}
                            </p>
                        )}
                        {results.map((variant) => (
                            <div
                                key={variant.id}
                                className="flex items-center justify-between gap-3 p-3 text-sm"
                            >
                                <div>
                                    <p className="font-medium">
                                        {variant.product_name}
                                        {variant.variant_name
                                            ? ` — ${variant.variant_name}`
                                            : ""}
                                    </p>
                                    <p className="text-xs text-ink-muted">
                                        {variant.reference ??
                                            variant.sku ??
                                            t("products.noReference")}{" "}
                                        · {t("exchanges.localStock")}{" "}
                                        {formatQuantity(
                                            variant.local_stock_available,
                                        )}
                                        {plannedRestock(variant.id) > 0
                                            ? ` + ${t("exchanges.plannedReturn")} ${formatQuantity(plannedRestock(variant.id))}`
                                            : ""}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span>
                                        {variant.unit_price_incl_tax
                                            ? formatMoney(
                                                  variant.unit_price_incl_tax,
                                                  order.currency_code,
                                              )
                                            : "—"}
                                    </span>
                                    <button
                                        type="button"
                                        disabled={
                                            variant.config_missing ||
                                            Number(
                                                variant.local_stock_available,
                                            ) +
                                                plannedRestock(variant.id) <=
                                                0 ||
                                            cart.some(
                                                (line) =>
                                                    line.id === variant.id,
                                            )
                                        }
                                        onClick={() => {
                                            setCart([
                                                ...cart,
                                                { ...variant, quantity: "1" },
                                            ]);
                                            setQuery("");
                                            setResults([]);
                                        }}
                                        className="rounded-field border border-line-strong px-3 py-2 disabled:opacity-40"
                                    >
                                        {t("common.add")}
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="mt-4 space-y-2">
                        {cart.map((line) => (
                            <div
                                key={line.id}
                                className="grid items-center gap-3 rounded-field bg-raised p-3 sm:grid-cols-[1fr_120px_auto]"
                            >
                                <span>{line.product_name}</span>
                                <input
                                    value={line.quantity}
                                    onChange={(e) =>
                                        setCart(
                                            cart.map((item) =>
                                                item.id === line.id
                                                    ? {
                                                          ...item,
                                                          quantity:
                                                              e.target.value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className="rounded-field border border-line-strong px-2 py-2"
                                />
                                <button
                                    type="button"
                                    onClick={() =>
                                        setCart(
                                            cart.filter(
                                                (item) => item.id !== line.id,
                                            ),
                                        )
                                    }
                                    className="text-danger"
                                >
                                    {t("common.remove")}
                                </button>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="rounded-card border border-line bg-surface p-5">
                    <h2 className="font-semibold text-ink">
                        3 — {t("exchanges.summary")}
                    </h2>
                    <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <dt className="text-sm text-ink-muted">
                                {t("exchanges.returnedValue")}
                            </dt>
                            <dd className="font-semibold">
                                {formatMoney(
                                    returnedTotal,
                                    order.currency_code,
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-ink-muted">
                                {t("exchanges.newItems")}
                            </dt>
                            <dd className="font-semibold">
                                {formatMoney(newTotal, order.currency_code)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-ink-muted">
                                {difference > 0
                                    ? t("exchanges.differenceDue")
                                    : difference < 0
                                      ? t("exchanges.toRefund")
                                      : t("exchanges.balance")}
                            </dt>
                            <dd className="text-lg font-bold text-primary">
                                {formatMoney(
                                    Math.abs(difference),
                                    order.currency_code,
                                )}
                            </dd>
                        </div>
                    </dl>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                        <label className="text-sm text-ink-muted">
                            {t("returns.reason")}
                            <input
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData("reason", e.target.value)
                                }
                                className="mt-1 w-full rounded-field border border-line-strong px-3 py-2"
                            />
                        </label>
                        <label className="text-sm text-ink-muted">
                            {t("returns.disposition")}
                            <select
                                value={form.data.disposition}
                                onChange={(e) =>
                                    form.setData("disposition", e.target.value)
                                }
                                className="mt-1 w-full rounded-field border border-line-strong px-3 py-2"
                            >
                                <option value="restock">
                                    {t("exchanges.restock")}
                                </option>
                                <option value="damaged">
                                    {t("returns.damagedDisposition")}
                                </option>
                            </select>
                        </label>
                    </div>
                    {!policy.within_policy && canOverride && (
                        <div className="mt-3">
                            <label className="text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.override_policy}
                                    onChange={(e) =>
                                        form.setData(
                                            "override_policy",
                                            e.target.checked,
                                        )
                                    }
                                />{" "}
                                {t("exchanges.managerOverride")}
                            </label>
                            {form.data.override_policy && (
                                <input
                                    value={form.data.override_reason}
                                    onChange={(e) =>
                                        form.setData(
                                            "override_reason",
                                            e.target.value,
                                        )
                                    }
                                    placeholder={t("exchanges.reasonRequired")}
                                    className="mt-2 w-full rounded-field border border-line-strong px-3 py-2"
                                />
                            )}
                        </div>
                    )}
                    {Object.values(form.errors).map(
                        (error) =>
                            error && (
                                <p
                                    key={error}
                                    className="mt-2 text-sm text-danger"
                                >
                                    {error}
                                </p>
                            ),
                    )}
                    <div className="mt-5 flex justify-end gap-2">
                        <Link
                            href={`/sales/orders/${order.id}`}
                            className="rounded-field border border-line-strong px-4 py-2"
                        >
                            {t("common.cancel")}
                        </Link>
                        <Button
                            disabled={
                                returnedTotal <= 0 ||
                                newTotal <= 0 ||
                                !policy.enabled
                            }
                            loading={form.processing}
                            onClick={submit}
                        >
                            {t("exchanges.create")}
                        </Button>
                    </div>
                </section>
            </div>
        </SalesLayout>
    );
}
