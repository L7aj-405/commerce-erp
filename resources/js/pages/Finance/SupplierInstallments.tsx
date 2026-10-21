import { Button, ButtonLink } from "@/components/ui/Button";
import EmptyState from "@/components/ui/EmptyState";
import PageHeader from "@/components/ui/PageHeader";
import Pagination from "@/components/ui/Pagination";
import { Sheet } from "@/components/ui/Sheet";
import ApplicationShell from "@/layouts/ApplicationShell";
import { useI18n } from "@/i18n";
import { formatDate, formatMoney } from "@/utils/format";
import { Head, router, useForm } from "@inertiajs/react";
import { type FormEvent, useMemo, useState } from "react";

type Entity = {
    id: number;
    name: string;
    code?: string;
    currency_code?: string;
};
type Plan = {
    id: number;
    number: string;
    supplier: Entity;
    procurement: { id: number; procurement_number: string } | null;
    reference: string | null;
    total: string;
    paid: string;
    remaining: string;
    committed: string;
    overdue: string;
    currency_code: string;
    installment_count: number;
    next_due_date: string | null;
};
type Props = {
    organization: Entity;
    filters: { supplier_id?: number; search?: string };
    plans: {
        data: Plan[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    suppliers: Entity[];
    stores: Entity[];
    accounts: Entity[];
    procurements: {
        id: number;
        procurement_number: string;
        supplier_id: number;
    }[];
    currencyCode: string;
    canManage: boolean;
};
const input =
    "h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm outline-none focus:border-primary";
const blank = (currency: string) => ({
    supplier_id: "",
    sales_order_procurement_id: "",
    store_id: "",
    label: "",
    reference: "",
    agreed_total: "",
    currency_code: currency,
    planned_payment_method: "cheque",
    preferred_financial_account_id: "",
    notes: "",
    installments: [
        { due_date: new Date().toISOString().slice(0, 10), amount: "" },
    ],
});
const scaled = (value: string) => {
    const [a = "0", b = ""] = value.replace(/\s/g, "").split(".");
    return BigInt(a || "0") * 10000n + BigInt((b + "0000").slice(0, 4));
};
const decimal = (value: bigint) =>
    `${value < 0n ? "-" : ""}${(value < 0n ? -value : value) / 10000n}.${String((value < 0n ? -value : value) % 10000n).padStart(4, "0")}`;

export default function SupplierInstallments(props: Props) {
    const { t } = useI18n();
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(props.filters.search ?? "");
    const form = useForm(blank(props.currencyCode));
    const rowsTotal = useMemo(() => {
        try {
            return decimal(
                form.data.installments.reduce(
                    (sum, row) => sum + scaled(row.amount || "0"),
                    0n,
                ),
            );
        } catch {
            return "0.0000";
        }
    }, [form.data.installments]);
    const difference = useMemo(() => {
        try {
            return decimal(
                scaled(form.data.agreed_total || "0") - scaled(rowsTotal),
            );
        } catch {
            return "0.0000";
        }
    }, [form.data.agreed_total, rowsTotal]);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post("/finance/situation-fin-de-mois/echeanciers-fournisseurs", {
            preserveScroll: true,
            onSuccess: () => setCreating(false),
        });
    };
    return (
        <ApplicationShell wide>
            <Head title={t("supplierInstallments.title")} />
            <PageHeader
                title={t("supplierInstallments.title")}
                description={`${props.organization.name} · ${t("supplierInstallments.description")}`}
                actions={
                    <>
                        <ButtonLink
                            href="/finance/situation-fin-de-mois"
                            variant="secondary"
                        >
                            {t("finance.monthEnd.title")}
                        </ButtonLink>
                        {props.canManage && (
                            <Button
                                onClick={() => {
                                    form.setData(blank(props.currencyCode));
                                    setCreating(true);
                                }}
                            >
                                {t("supplierInstallments.create")}
                            </Button>
                        )}
                    </>
                }
            />
            <section className="mb-5 rounded-card border border-line bg-surface p-4">
                <form
                    className="flex flex-wrap gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            "/finance/situation-fin-de-mois/echeanciers-fournisseurs",
                            {
                                search,
                                supplier_id: props.filters.supplier_id ?? "",
                            },
                            { preserveState: true },
                        );
                    }}
                >
                    <input
                        className={`${input} min-w-64 flex-1`}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t(
                            "supplierInstallments.searchPlaceholder",
                        )}
                    />
                    <select
                        className={`${input} max-w-64`}
                        value={props.filters.supplier_id ?? ""}
                        onChange={(e) =>
                            router.get(
                                "/finance/situation-fin-de-mois/echeanciers-fournisseurs",
                                { supplier_id: e.target.value, search },
                            )
                        }
                    >
                        <option value="">{t("common.allSuppliers")}</option>
                        {props.suppliers.map((x) => (
                            <option key={x.id} value={x.id}>
                                {x.name}
                            </option>
                        ))}
                    </select>
                    <Button type="submit" variant="secondary">
                        {t("common.search")}
                    </Button>
                </form>
            </section>
            {props.plans.data.length === 0 ? (
                <EmptyState
                    title={t("supplierInstallments.empty")}
                    description={t("supplierInstallments.emptyDescription")}
                    actions={
                        props.canManage ? (
                            <Button onClick={() => setCreating(true)}>
                                {t("supplierInstallments.create")}
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <section className="overflow-hidden rounded-card border border-line bg-surface">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1100px] text-sm">
                            <thead className="bg-raised text-start text-xs uppercase text-ink-muted">
                                <tr>
                                    {[
                                        "plan",
                                        "supplier",
                                        "reference",
                                        "planned",
                                        "paid",
                                        "committed",
                                        "remaining",
                                        "overdue",
                                        "nextDue",
                                        "actions",
                                    ].map((key) => (
                                        <th key={key} className="px-4 py-3">
                                            {t(
                                                `supplierInstallments.column.${key}` as Parameters<
                                                    typeof t
                                                >[0],
                                            )}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {props.plans.data.map((p) => (
                                    <tr key={p.id}>
                                        <td className="px-4 py-3 font-medium">
                                            <bdi>{p.number}</bdi>
                                            <p className="text-xs text-ink-muted">
                                                {t(
                                                    "supplierInstallments.installmentCount",
                                                ).replace(
                                                    "{count}",
                                                    String(p.installment_count),
                                                )}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            {p.supplier.name}
                                        </td>
                                        <td className="px-4 py-3">
                                            {p.reference ?? "—"}
                                        </td>
                                        <td className="px-4 py-3 text-end">
                                            {formatMoney(
                                                p.total,
                                                p.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-end">
                                            {formatMoney(
                                                p.paid,
                                                p.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-end">
                                            {formatMoney(
                                                p.committed,
                                                p.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-end font-semibold">
                                            {formatMoney(
                                                p.remaining,
                                                p.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-end text-danger">
                                            {formatMoney(
                                                p.overdue,
                                                p.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {p.next_due_date
                                                ? formatDate(p.next_due_date)
                                                : "—"}
                                        </td>
                                        <td className="px-4 py-3">
                                            <ButtonLink
                                                href={`/finance/situation-fin-de-mois/echeanciers-fournisseurs/${p.id}`}
                                                size="sm"
                                                variant="secondary"
                                            >
                                                {t("common.view")}
                                            </ButtonLink>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            <Pagination links={props.plans.links} />
            <Sheet
                open={creating}
                onClose={() => setCreating(false)}
                title={t("supplierInstallments.createTitle")}
                description={t("supplierInstallments.createDescription")}
                widthClassName="max-w-3xl"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setCreating(false)}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button
                            form="plan-form"
                            type="submit"
                            loading={form.processing}
                        >
                            {t("supplierInstallments.create")}
                        </Button>
                    </div>
                }
            >
                <form
                    id="plan-form"
                    onSubmit={submit}
                    className="grid gap-4 p-5"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Select
                            label={t("common.supplier")}
                            value={form.data.supplier_id}
                            onChange={(v) => {
                                form.setData("supplier_id", v);
                                form.setData("sales_order_procurement_id", "");
                            }}
                            error={form.errors.supplier_id}
                        >
                            <option value="">{t("common.select")}</option>
                            {props.suppliers.map((x) => (
                                <option key={x.id} value={x.id}>
                                    {x.name}
                                </option>
                            ))}
                        </Select>
                        <Select
                            label={t(
                                "supplierInstallments.optionalProcurement",
                            )}
                            value={form.data.sales_order_procurement_id}
                            onChange={(v) =>
                                form.setData("sales_order_procurement_id", v)
                            }
                        >
                            <option value="">{t("common.none")}</option>
                            {props.procurements
                                .filter(
                                    (x) =>
                                        String(x.supplier_id) ===
                                        form.data.supplier_id,
                                )
                                .map((x) => (
                                    <option key={x.id} value={x.id}>
                                        {x.procurement_number}
                                    </option>
                                ))}
                        </Select>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t("common.reference")}
                            value={form.data.reference}
                            onChange={(v) => form.setData("reference", v)}
                        />
                        <Field
                            label={t("supplierInstallments.agreedTotal")}
                            type="number"
                            step="0.0001"
                            value={form.data.agreed_total}
                            onChange={(v) => form.setData("agreed_total", v)}
                            error={form.errors.agreed_total}
                        />
                    </div>
                    <div className="rounded-field border border-line p-3">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="font-semibold">
                                {t("supplierInstallments.installments")}
                            </h3>
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                onClick={() =>
                                    form.setData("installments", [
                                        ...form.data.installments,
                                        { due_date: "", amount: "" },
                                    ])
                                }
                            >
                                {t("common.add")}
                            </Button>
                        </div>
                        <div className="space-y-2">
                            {form.data.installments.map((row, i) => (
                                <div
                                    key={i}
                                    className="grid grid-cols-[1fr_1fr_auto] gap-2"
                                >
                                    <input
                                        className={input}
                                        type="date"
                                        value={row.due_date}
                                        onChange={(e) =>
                                            form.setData(
                                                "installments",
                                                form.data.installments.map(
                                                    (x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  due_date:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : x,
                                                ),
                                            )
                                        }
                                    />
                                    <input
                                        className={input}
                                        type="number"
                                        step="0.0001"
                                        value={row.amount}
                                        onChange={(e) =>
                                            form.setData(
                                                "installments",
                                                form.data.installments.map(
                                                    (x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  amount: e
                                                                      .target
                                                                      .value,
                                                              }
                                                            : x,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={
                                            form.data.installments.length === 1
                                        }
                                        onClick={() =>
                                            form.setData(
                                                "installments",
                                                form.data.installments.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        {t("common.remove")}
                                    </Button>
                                </div>
                            ))}
                        </div>
                        {form.errors.installments && (
                            <p className="mt-2 text-xs text-danger">
                                {form.errors.installments}
                            </p>
                        )}
                        <div className="mt-3 grid grid-cols-3 gap-3 text-sm">
                            <span>
                                {t("supplierInstallments.agreedTotal")}
                                <br />
                                <b>{form.data.agreed_total || "0"}</b>
                            </span>
                            <span>
                                {t("supplierInstallments.installments")}
                                <br />
                                <b>{rowsTotal}</b>
                            </span>
                            <span>
                                {t("supplierInstallments.difference")}
                                <br />
                                <b
                                    className={
                                        difference === "0.0000"
                                            ? "text-success"
                                            : "text-danger"
                                    }
                                >
                                    {difference}
                                </b>
                            </span>
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Select
                            label={t("finance.monthEnd.store")}
                            value={form.data.store_id}
                            onChange={(v) => form.setData("store_id", v)}
                        >
                            <option value="">
                                {t("recurringObligations.wholeOrganization")}
                            </option>
                            {props.stores.map((x) => (
                                <option key={x.id} value={x.id}>
                                    {x.name}
                                </option>
                            ))}
                        </Select>
                        <Select
                            label={t("recurringObligations.preferredAccount")}
                            value={form.data.preferred_financial_account_id}
                            onChange={(v) =>
                                form.setData(
                                    "preferred_financial_account_id",
                                    v,
                                )
                            }
                        >
                            <option value="">{t("common.undefined")}</option>
                            {props.accounts.map((x) => (
                                <option key={x.id} value={x.id}>
                                    {x.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <label>
                        <span className="mb-1 block text-sm font-medium">
                            {t("common.notes")}
                        </span>
                        <textarea
                            className={`${input} min-h-24 py-2`}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData("notes", e.target.value)
                            }
                        />
                    </label>
                </form>
            </Sheet>
        </ApplicationShell>
    );
}
function Field({
    label,
    value,
    onChange,
    error,
    ...rest
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    [key: string]: unknown;
}) {
    return (
        <label>
            <span className="mb-1 block text-sm font-medium">{label}</span>
            <input
                {...rest}
                className={input}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
            {error && <p className="text-xs text-danger">{error}</p>}
        </label>
    );
}
function Select({
    label,
    value,
    onChange,
    error,
    children,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <label>
            <span className="mb-1 block text-sm font-medium">{label}</span>
            <select
                className={input}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                {children}
            </select>
            {error && <p className="text-xs text-danger">{error}</p>}
        </label>
    );
}
