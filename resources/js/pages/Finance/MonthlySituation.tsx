import FinanceFilters, {
    type FinanceStore,
} from "@/components/finance/FinanceFilters";
import { Button, ButtonLink, DownloadLink } from "@/components/ui/Button";
import EmptyState from "@/components/ui/EmptyState";
import { MetricCard } from "@/components/ui/MetricCard";
import PageHeader from "@/components/ui/PageHeader";
import Pagination from "@/components/ui/Pagination";
import { Sheet } from "@/components/ui/Sheet";
import ApplicationShell from "@/layouts/ApplicationShell";
import { useI18n } from "@/i18n";
import {
    formatDate,
    formatDateTime,
    formatEditableNumber,
    formatMoney,
} from "@/utils/format";
import { Head, router, useForm } from "@inertiajs/react";
import {
    type FormEvent,
    type InputHTMLAttributes,
    type ReactNode,
    useMemo,
    useState,
} from "react";

type Option = { value: string; label: string };
type Entity = {
    id: number;
    name: string;
    code?: string;
    currency_code?: string;
};
type PaymentHistory = {
    allocation_id: number;
    payment_id: number;
    number: string;
    date: string;
    method: string;
    amount: string;
    status: string;
    reference: string | null;
    account: Entity | null;
    reversal_reason: string | null;
};
type Instrument = {
    id: number;
    number: string;
    type: string;
    external_reference: string;
    amount: string;
    issue_date: string;
    maturity_date: string;
    state: string;
    status: string;
    status_label: string;
    account: Entity | null;
};
type FutureObligation = {
    id: number;
    number: string;
    due_date: string;
    supplier: string;
    reference: string | null;
    amount: string;
    currency_code: string;
    source: string;
};
type ExpectedCollection = {
    id: number;
    sales_order_id: number;
    order_number: string;
    customer: string;
    label: string | null;
    reference: string | null;
    expected_date: string;
    amount: string;
    matched: string;
    remaining: string;
    currency_code: string;
    status: string;
    notes: string | null;
};
type ReceivableOrder = {
    id: number;
    number: string;
    customer: string;
    outstanding: string;
    currency_code: string;
};
type AllocationOption = {
    id: number;
    sales_order_id: number;
    number: string;
    date: string;
    available: string;
};
type Treasury = {
    mode: "historical" | "current" | "future";
    balance: {
        complete: boolean;
        total: string | null;
        configured_count: number;
        unconfigured_count: number;
        as_of: string;
        reason: string | null;
    };
    expected: string;
    remaining_outflows: string;
    overdue: string;
    projected: string | null;
    shortage: string | null;
    currency_code: string;
};
type MonthReview = {
    id: number | null;
    status: "open" | "reviewed" | "closed";
    reviewed_at: string | null;
    reviewed_by: string | null;
    closed_at: string | null;
    closed_by: string | null;
    reopened_at: string | null;
    reopened_by: string | null;
    note: string | null;
    stale: boolean;
    warnings: { key: string; message: string }[];
    can_review: boolean;
    can_close: boolean;
    scope_note: string | null;
};
type Obligation = {
    id: number;
    number: string;
    category: string;
    category_label: string;
    label: string;
    store: Entity | null;
    supplier: Entity | null;
    supplier_id: number | null;
    beneficiary_name: string | null;
    beneficiary: string;
    reference: string | null;
    due_date: string;
    planned_amount: string;
    paid_amount: string;
    remaining_amount: string;
    currency_code: string;
    planned_payment_method: string | null;
    preferred_financial_account_id: number | null;
    preferred_financial_account: Entity | null;
    notes: string | null;
    status: string;
    status_label: string;
    carry_forward: boolean;
    created_by: string | null;
    created_at: string | null;
    cancelled_at: string | null;
    cancelled_by: string | null;
    cancellation_reason: string | null;
    payments: PaymentHistory[];
    recurring_template_id: number | null;
    recurring_period: string | null;
    committed_amount: string;
    instruments: Instrument[];
};
type Paginator = {
    data: Obligation[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    filters: {
        category?: string;
        status?: string;
        supplier_id?: number;
        search?: string;
    };
    stores: FinanceStore[];
    suppliers: Entity[];
    financialAccounts: Entity[];
    categories: Option[];
    paymentMethods: Option[];
    currencyCode: string;
    kpis: {
        planned: string;
        paid: string;
        remaining: string;
        overdue: string;
        count: number;
        overdue_count: number;
    };
    obligations: Paginator;
    futureObligations: FutureObligation[];
    recurringTemplatesCount: number;
    can: { manage: boolean; pay: boolean; export: boolean };
    treasury: Treasury;
    expectedCollections: ExpectedCollection[];
    receivableOrders: ReceivableOrder[];
    collectionAllocationOptions: AllocationOption[];
    monthReview: MonthReview;
};

const inputClass = "control-input";
const selectClass = "control-select";
const textareaClass = "control-input min-h-24 py-2";

const emptyObligation = (currency: string) => ({
    store_id: "",
    category: "other",
    label: "",
    supplier_id: "",
    beneficiary_name: "",
    reference: "",
    due_date: new Date().toISOString().slice(0, 10),
    planned_amount: "",
    currency_code: currency,
    planned_payment_method: "",
    preferred_financial_account_id: "",
    notes: "",
});
const subtractExact = (left: string, right: string) => {
    const parse = (v: string) => {
        const [a = "0", b = ""] = v.split(".");
        return BigInt(a) * 10000n + BigInt((b + "0000").slice(0, 4));
    };
    const n = parse(left) - parse(right);
    const safe = n < 0n ? 0n : n;
    return `${safe / 10000n}.${String(safe % 10000n).padStart(4, "0")}`;
};

export default function MonthlySituation(props: Props) {
    const { t } = useI18n();
    const {
        period,
        storeId,
        stores,
        suppliers,
        financialAccounts,
        categories,
        paymentMethods,
        currencyCode,
        kpis,
        obligations,
        can,
    } = props;
    const [editing, setEditing] = useState<Obligation | null>(null);
    const [detail, setDetail] = useState<Obligation | null>(null);
    const [paying, setPaying] = useState<Obligation | null>(null);
    const [creating, setCreating] = useState(false);
    const [cancelling, setCancelling] = useState<Obligation | null>(null);
    const [reversing, setReversing] = useState<PaymentHistory | null>(null);
    const [instrumentFor, setInstrumentFor] = useState<Obligation | null>(null);
    const [cancellingInstrument, setCancellingInstrument] =
        useState<Instrument | null>(null);
    const [expectedEditing, setExpectedEditing] =
        useState<ExpectedCollection | null>(null);
    const [expectedCreating, setExpectedCreating] = useState(false);
    const [expectedCancelling, setExpectedCancelling] =
        useState<ExpectedCollection | null>(null);
    const [expectedMatching, setExpectedMatching] =
        useState<ExpectedCollection | null>(null);
    const [search, setSearch] = useState(props.filters.search ?? "");
    const [closingMonth, setClosingMonth] = useState(false);
    const [reopeningMonth, setReopeningMonth] = useState(false);

    const obligationForm = useForm(emptyObligation(currencyCode));
    const paymentForm = useForm({
        store_id: "",
        financial_account_id: "",
        supplier_id: "",
        beneficiary_name: "",
        payment_method: "bank_transfer",
        amount: "",
        currency_code: currencyCode,
        payment_date: new Date().toISOString().slice(0, 10),
        reference: "",
        notes: "",
        idempotency_key: "",
        allocations: [{ obligation_id: 0, amount: "" }],
    });
    const cancelForm = useForm({ reason: "" });
    const reverseForm = useForm({ reason: "" });
    const instrumentCancelForm = useForm({ reason: "" });
    const instrumentForm = useForm({
        type: "cheque",
        external_reference: "",
        amount: "",
        issue_date: new Date().toISOString().slice(0, 10),
        maturity_date: new Date().toISOString().slice(0, 10),
        financial_account_id: "",
        notes: "",
    });
    const generationForm = useForm({ month: period });
    const expectedForm = useForm({
        sales_order_id: "",
        label: "",
        reference: "",
        expected_date: new Date().toISOString().slice(0, 10),
        expected_amount: "",
        notes: "",
    });
    const expectedCancelForm = useForm({ reason: "" });
    const matchForm = useForm({ payment_allocation_id: "", amount: "" });
    const reviewForm = useForm({ month: period, note: "" });
    const closeForm = useForm({
        month: period,
        note: "",
        acknowledge_warnings: false,
    });
    const reopenForm = useForm({ month: period, reason: "" });

    const applyFilters = (next: Record<string, string | number | undefined>) =>
        router.get(
            "/finance/situation-fin-de-mois",
            {
                month: period,
                store_id: storeId ?? "",
                category: props.filters.category ?? "",
                status: props.filters.status ?? "",
                supplier_id: props.filters.supplier_id ?? "",
                search: props.filters.search ?? "",
                ...next,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const openCreate = () => {
        obligationForm.setData(emptyObligation(currencyCode));
        obligationForm.clearErrors();
        setCreating(true);
    };
    const openEdit = (row: Obligation) => {
        obligationForm.setData({
            store_id: row.store?.id ? String(row.store.id) : "",
            category: row.category,
            label: row.label,
            supplier_id: row.supplier_id ? String(row.supplier_id) : "",
            beneficiary_name: row.beneficiary_name ?? "",
            reference: row.reference ?? "",
            due_date: row.due_date,
            planned_amount: formatEditableNumber(row.planned_amount),
            currency_code: row.currency_code,
            planned_payment_method: row.planned_payment_method ?? "",
            preferred_financial_account_id: row.preferred_financial_account_id
                ? String(row.preferred_financial_account_id)
                : "",
            notes: row.notes ?? "",
        });
        obligationForm.clearErrors();
        setEditing(row);
    };
    const openPayment = (row: Obligation) => {
        paymentForm.setData({
            store_id: row.store?.id ? String(row.store.id) : "",
            financial_account_id: row.preferred_financial_account_id
                ? String(row.preferred_financial_account_id)
                : "",
            supplier_id: row.supplier_id ? String(row.supplier_id) : "",
            beneficiary_name: row.beneficiary_name ?? "",
            payment_method: row.planned_payment_method ?? "bank_transfer",
            amount: formatEditableNumber(
                subtractExact(row.remaining_amount, row.committed_amount),
            ),
            currency_code: row.currency_code,
            payment_date: new Date().toISOString().slice(0, 10),
            reference: row.reference ?? "",
            notes: "",
            idempotency_key: crypto.randomUUID(),
            allocations: [
                {
                    obligation_id: row.id,
                    amount: formatEditableNumber(
                        subtractExact(
                            row.remaining_amount,
                            row.committed_amount,
                        ),
                    ),
                },
            ],
        });
        paymentForm.clearErrors();
        setPaying(row);
    };
    const openInstrument = (row: Obligation) => {
        instrumentForm.setData({
            type: "cheque",
            external_reference: "",
            amount: formatEditableNumber(
                subtractExact(row.remaining_amount, row.committed_amount),
            ),
            issue_date: new Date().toISOString().slice(0, 10),
            maturity_date: row.due_date,
            financial_account_id: row.preferred_financial_account_id
                ? String(row.preferred_financial_account_id)
                : "",
            notes: "",
        });
        instrumentForm.clearErrors();
        setInstrumentFor(row);
    };
    const syncPaymentAmount = (amount: string) =>
        paymentForm.setData((data) => ({
            ...data,
            amount,
            allocations: [{ ...data.allocations[0], amount }],
        }));

    const submitObligation = (event: FormEvent) => {
        event.preventDefault();
        if (editing)
            obligationForm.patch(
                `/finance/situation-fin-de-mois/obligations/${editing.id}`,
                { preserveScroll: true, onSuccess: () => setEditing(null) },
            );
        else
            obligationForm.post("/finance/situation-fin-de-mois/obligations", {
                preserveScroll: true,
                onSuccess: () => setCreating(false),
            });
    };
    const submitPayment = (event: FormEvent) => {
        event.preventDefault();
        paymentForm.post("/finance/situation-fin-de-mois/outgoing-payments", {
            preserveScroll: true,
            onSuccess: () => setPaying(null),
        });
    };
    const hasRows = obligations.data.length > 0;
    const filterActive = Boolean(
        props.filters.category ||
        props.filters.status ||
        props.filters.supplier_id ||
        props.filters.search ||
        storeId,
    );
    const exportQuery = new URLSearchParams({
        month: period,
        ...(storeId ? { store_id: String(storeId) } : {}),
        ...(props.filters.category ? { category: props.filters.category } : {}),
        ...(props.filters.status ? { status: props.filters.status } : {}),
        ...(props.filters.supplier_id
            ? { supplier_id: String(props.filters.supplier_id) }
            : {}),
        ...(props.filters.search ? { search: props.filters.search } : {}),
    }).toString();

    return (
        <ApplicationShell wide>
            <Head title={t("finance.monthEnd.title")} />
            <PageHeader
                title={t("finance.monthEnd.title")}
                description={`${props.organization.name} · ${props.periodLabel} · ${t("finance.monthEnd.description")}`}
                actions={
                    <>
                        <ButtonLink
                            href="/finance/situation-fin-de-mois/echeanciers-fournisseurs"
                            variant="secondary"
                        >
                            {t("finance.monthEnd.supplierSchedules")}
                        </ButtonLink>
                        <ButtonLink
                            href="/finance/situation-fin-de-mois/charges-recurrentes"
                            variant="secondary"
                        >
                            {t("finance.monthEnd.recurringCharges")}
                        </ButtonLink>
                        {can.export && (
                            <DownloadLink
                                href={`/finance/situation-fin-de-mois/export/xlsx?${exportQuery}`}
                                variant="secondary"
                            >
                                {t("common.exportExcel")}
                            </DownloadLink>
                        )}
                        {can.manage && (
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    expectedForm.reset();
                                    expectedForm.setData(
                                        "expected_date",
                                        new Date().toISOString().slice(0, 10),
                                    );
                                    setExpectedCreating(true);
                                }}
                            >
                                {t("finance.monthEnd.planCollection")}
                            </Button>
                        )}
                        {can.manage && props.recurringTemplatesCount > 0 && (
                            <Button
                                variant="secondary"
                                loading={generationForm.processing}
                                onClick={() =>
                                    generationForm.post(
                                        "/finance/situation-fin-de-mois/charges-recurrentes/generate",
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t("finance.monthEnd.generateMonth")}
                            </Button>
                        )}
                        {can.manage && (
                            <Button onClick={openCreate}>
                                {t("finance.monthEnd.addObligation")}
                            </Button>
                        )}
                    </>
                }
            />

            <FinanceFilters
                path="/finance/situation-fin-de-mois"
                period={period}
                storeId={storeId}
                stores={stores}
                extra={{
                    category: props.filters.category,
                    status: props.filters.status,
                    supplier_id: props.filters.supplier_id,
                    search: props.filters.search,
                }}
            />

            <section className="mb-6 rounded-card border border-line bg-surface p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h2 className="font-semibold text-ink">
                                {t("finance.monthEnd.reviewOf")}{" "}
                                {props.periodLabel}
                            </h2>
                            <ReviewStatus status={props.monthReview.status} />
                        </div>
                        <p className="mt-1 text-sm text-ink-muted">
                            {props.monthReview.status === "closed" &&
                            props.monthReview.closed_at
                                ? `${t("finance.monthEnd.closedOn")} ${formatDateTime(props.monthReview.closed_at)} ${t("finance.monthEnd.by")} ${props.monthReview.closed_by ?? "—"}`
                                : props.monthReview.status === "reviewed" &&
                                    props.monthReview.reviewed_at
                                  ? `${t("finance.monthEnd.reviewedOn")} ${formatDateTime(props.monthReview.reviewed_at)} ${t("finance.monthEnd.by")} ${props.monthReview.reviewed_by ?? "—"}`
                                  : t("finance.monthEnd.openMonth")}
                        </p>
                        {props.monthReview.scope_note && (
                            <p className="mt-1 text-xs text-ink-faint">
                                {props.monthReview.scope_note}
                            </p>
                        )}
                    </div>
                    {can.manage && (
                        <div className="flex flex-wrap gap-2">
                            {props.monthReview.status !== "closed" &&
                                props.monthReview.can_review && (
                                    <Button
                                        variant="secondary"
                                        loading={reviewForm.processing}
                                        onClick={() =>
                                            reviewForm.post(
                                                "/finance/situation-fin-de-mois/review",
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t("finance.monthEnd.markReviewed")}
                                    </Button>
                                )}
                            {props.monthReview.status !== "closed" &&
                                props.monthReview.can_close && (
                                    <Button
                                        onClick={() => setClosingMonth(true)}
                                    >
                                        {t("finance.monthEnd.close")}
                                    </Button>
                                )}
                            {props.monthReview.status === "closed" && (
                                <Button
                                    variant="secondary"
                                    onClick={() => setReopeningMonth(true)}
                                >
                                    {t("finance.monthEnd.reopen")}
                                </Button>
                            )}
                        </div>
                    )}
                </div>
                {(props.monthReview.stale ||
                    props.monthReview.warnings.length > 0 ||
                    Number(props.treasury.overdue) > 0 ||
                    props.expectedCollections.some(
                        (x) => x.status === "overdue",
                    )) && (
                    <div className="mt-4 grid gap-2 text-sm">
                        {props.monthReview.stale && (
                            <div className="rounded-field bg-warning-soft px-3 py-2 text-warning">
                                {t("finance.monthEnd.staleWarning")}
                            </div>
                        )}
                        {props.monthReview.warnings.map((w) => (
                            <div
                                key={w.key}
                                className="rounded-field bg-warning-soft px-3 py-2 text-warning"
                            >
                                {w.message}
                            </div>
                        ))}
                        {Number(props.treasury.overdue) > 0 && (
                            <div className="rounded-field bg-danger-soft px-3 py-2 text-danger">
                                {t("finance.monthEnd.overdueObligations")}
                            </div>
                        )}
                        {props.expectedCollections.some(
                            (x) => x.status === "overdue",
                        ) && (
                            <div className="rounded-field bg-warning-soft px-3 py-2 text-warning">
                                {t("finance.monthEnd.overdueCollections")}
                            </div>
                        )}
                    </div>
                )}
            </section>

            <div className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label={
                        props.treasury.mode === "historical"
                            ? t("finance.monthEnd.historicalPosition")
                            : t("finance.monthEnd.currentBalance")
                    }
                    value={
                        props.treasury.balance.complete
                            ? formatMoney(
                                  props.treasury.balance.total!,
                                  props.treasury.currency_code,
                              )
                            : t("common.notConfigured")
                    }
                    helper={
                        t("finance.monthEnd.balanceCoverage")
                            .replace(
                                "{configured}",
                                String(props.treasury.balance.configured_count),
                            )
                            .replace(
                                "{unknown}",
                                String(
                                    props.treasury.balance.unconfigured_count,
                                ),
                            ) +
                        (storeId
                            ? ` · ${t("finance.monthEnd.organizationBalance")}`
                            : "")
                    }
                    unconfigured={!props.treasury.balance.complete}
                />
                <MetricCard
                    label={t("finance.monthEnd.expectedCollections")}
                    value={formatMoney(props.treasury.expected, currencyCode)}
                    tone="positive"
                />
                <MetricCard
                    label={t("finance.monthEnd.remainingObligations")}
                    value={formatMoney(
                        props.treasury.remaining_outflows,
                        currencyCode,
                    )}
                    tone="negative"
                />
                <MetricCard
                    label={t("finance.monthEnd.forecast")}
                    value={
                        props.treasury.projected === null
                            ? t("common.unavailable")
                            : formatMoney(
                                  props.treasury.projected,
                                  currencyCode,
                              )
                    }
                    helper={
                        !props.treasury.balance.complete
                            ? t("finance.monthEnd.configureOpeningBalance")
                            : undefined
                    }
                    tone={
                        props.treasury.projected !== null &&
                        Number(props.treasury.projected) < 0
                            ? "negative"
                            : "forecast"
                    }
                    unconfigured={props.treasury.projected === null}
                />
            </div>
            <div className="mb-6 grid gap-3 sm:grid-cols-2">
                <MetricCard
                    level="secondary"
                    label={t("finance.monthEnd.overdue")}
                    value={formatMoney(props.treasury.overdue, currencyCode)}
                    tone={
                        Number(props.treasury.overdue) > 0
                            ? "negative"
                            : "neutral"
                    }
                />
                <MetricCard
                    level="secondary"
                    label={t("finance.monthEnd.shortage")}
                    value={
                        props.treasury.shortage === null
                            ? t("common.unavailable")
                            : Number(props.treasury.shortage) > 0
                              ? formatMoney(
                                    props.treasury.shortage,
                                    currencyCode,
                                )
                              : t("common.none")
                    }
                    tone={
                        Number(props.treasury.shortage) > 0
                            ? "negative"
                            : "neutral"
                    }
                />
            </div>

            {props.treasury.mode !== "historical" && (
                <div className="mb-6 rounded-card border border-line bg-surface p-4 text-sm">
                    <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                        <span>{t("finance.monthEnd.currentBalanceShort")}</span>
                        <strong>
                            {props.treasury.balance.complete
                                ? formatMoney(
                                      props.treasury.balance.total!,
                                      currencyCode,
                                  )
                                : t("common.notConfigured")}
                        </strong>
                        <span>
                            + {t("finance.monthEnd.expectedCollections")}
                        </span>
                        <strong>
                            {formatMoney(props.treasury.expected, currencyCode)}
                        </strong>
                        <span>
                            − {t("finance.monthEnd.remainingUntilPeriodEnd")}
                        </span>
                        <strong>
                            {formatMoney(
                                props.treasury.remaining_outflows,
                                currencyCode,
                            )}
                        </strong>
                        <span className="border-t border-line pt-2 font-semibold">
                            {t("finance.monthEnd.forecast")}
                        </span>
                        <strong className="border-t border-line pt-2">
                            {props.treasury.projected === null
                                ? t("common.unavailable")
                                : formatMoney(
                                      props.treasury.projected,
                                      currencyCode,
                                  )}
                        </strong>
                    </div>
                </div>
            )}

            <section className="mb-4 rounded-card border border-line bg-surface p-4">
                <form
                    className="grid gap-3 lg:grid-cols-[minmax(240px,1fr)_180px_180px_220px_auto]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilters({ search });
                    }}
                >
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("finance.monthEnd.searchPlaceholder")}
                        className={inputClass}
                    />
                    <select
                        value={props.filters.category ?? ""}
                        onChange={(e) =>
                            applyFilters({ category: e.target.value })
                        }
                        className={selectClass}
                    >
                        <option value="">
                            {t("finance.monthEnd.allTypes")}
                        </option>
                        {categories.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                    <select
                        value={props.filters.status ?? ""}
                        onChange={(e) =>
                            applyFilters({ status: e.target.value })
                        }
                        className={selectClass}
                    >
                        <option value="">{t("common.allStatuses")}</option>
                        <option value="upcoming">{t("status.upcoming")}</option>
                        <option value="partial">
                            {t("status.partially_paid")}
                        </option>
                        <option value="paid">{t("status.paid")}</option>
                        <option value="overdue">{t("status.overdue")}</option>
                        <option value="cancelled">
                            {t("status.cancelled")}
                        </option>
                    </select>
                    <select
                        value={props.filters.supplier_id ?? ""}
                        onChange={(e) =>
                            applyFilters({ supplier_id: e.target.value })
                        }
                        className={selectClass}
                    >
                        <option value="">
                            {t("finance.monthEnd.allSuppliers")}
                        </option>
                        {suppliers.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                    <div className="flex gap-2">
                        <Button type="submit" variant="secondary">
                            {t("common.search")}
                        </Button>
                        {filterActive && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    router.get(
                                        "/finance/situation-fin-de-mois",
                                        { month: period },
                                    )
                                }
                            >
                                {t("finance.monthEnd.clear")}
                            </Button>
                        )}
                    </div>
                </form>
            </section>

            {!hasRows ? (
                <EmptyState
                    title={t("finance.monthEnd.emptyTitle")}
                    description={
                        props.recurringTemplatesCount > 0
                            ? t("finance.monthEnd.emptyRecurring")
                            : t("finance.monthEnd.emptyManual")
                    }
                    actions={
                        can.manage ? (
                            <div className="flex flex-wrap justify-center gap-2">
                                <Button onClick={openCreate}>
                                    {t("finance.monthEnd.addObligation")}
                                </Button>
                                <ButtonLink
                                    href="/finance/situation-fin-de-mois/charges-recurrentes"
                                    variant="secondary"
                                >
                                    {t("finance.monthEnd.addRecurring")}
                                </ButtonLink>
                                {props.recurringTemplatesCount > 0 && (
                                    <Button
                                        variant="secondary"
                                        loading={generationForm.processing}
                                        onClick={() =>
                                            generationForm.post(
                                                "/finance/situation-fin-de-mois/charges-recurrentes/generate",
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t("finance.monthEnd.generateCharges")}
                                    </Button>
                                )}
                            </div>
                        ) : undefined
                    }
                />
            ) : (
                <section className="overflow-hidden rounded-card border border-line bg-surface">
                    <div className="overflow-x-auto">
                        <table className="erp-table min-w-[1120px]">
                            <thead>
                                <tr>
                                    {[
                                        t("finance.monthEnd.dueDate"),
                                        t("finance.monthEnd.number"),
                                        t("finance.monthEnd.label"),
                                        t("finance.monthEnd.type"),
                                        t(
                                            "finance.monthEnd.supplierBeneficiary",
                                        ),
                                        t("common.reference"),
                                        t("finance.monthEnd.planned"),
                                        t("orders.show.paid"),
                                        t("orders.show.remaining"),
                                        t("common.status"),
                                        t("common.actions"),
                                    ].map((h) => (
                                        <th
                                            key={h}
                                            className="px-4 py-3 font-semibold"
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {obligations.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={
                                            row.carry_forward
                                                ? "bg-danger-soft/30"
                                                : ""
                                        }
                                    >
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {formatDate(row.due_date)}
                                        </td>
                                        <td
                                            className="bidi-isolate px-4 py-3 font-medium text-ink"
                                            dir="ltr"
                                        >
                                            {row.number}
                                        </td>
                                        <td className="px-4 py-3">
                                            <button
                                                className="text-left font-medium text-ink hover:underline"
                                                onClick={() => setDetail(row)}
                                            >
                                                {row.label}
                                            </button>
                                            {row.recurring_template_id && (
                                                <span className="ml-2 inline-flex rounded-full bg-sage px-2 py-0.5 text-[10px] font-semibold text-primary">
                                                    {t(
                                                        "finance.monthEnd.recurring",
                                                    )}
                                                </span>
                                            )}
                                            {row.store && (
                                                <p className="text-xs text-ink-muted">
                                                    {row.store.name}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.category_label}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.beneficiary}
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">
                                            {row.reference ?? "—"}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {formatMoney(
                                                row.planned_amount,
                                                row.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {formatMoney(
                                                row.paid_amount,
                                                row.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                            {formatMoney(
                                                row.remaining_amount,
                                                row.currency_code,
                                            )}
                                            {Number(row.committed_amount) >
                                                0 && (
                                                <p className="text-[10px] font-normal text-warning">
                                                    {t(
                                                        "finance.monthEnd.committed",
                                                    )}{" "}
                                                    {formatMoney(
                                                        row.committed_amount,
                                                        row.currency_code,
                                                    )}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Status
                                                value={row.status}
                                                label={row.status_label}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setDetail(row)
                                                    }
                                                >
                                                    {t("common.view")}
                                                </Button>
                                                {can.pay &&
                                                    ![
                                                        "paid",
                                                        "cancelled",
                                                    ].includes(row.status) && (
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                openPayment(row)
                                                            }
                                                        >
                                                            {t(
                                                                "finance.monthEnd.pay",
                                                            )}
                                                        </Button>
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            <Pagination links={obligations.links} />

            <section className="mt-7">
                <div className="mb-3">
                    <h2 className="text-lg font-semibold">
                        {t("finance.monthEnd.expectedCollections")}
                    </h2>
                    <p className="text-sm text-ink-muted">
                        {t("finance.monthEnd.forecastOnly")}
                    </p>
                </div>
                {props.expectedCollections.length === 0 ? (
                    <div className="rounded-card border border-dashed border-line p-6 text-center text-sm text-ink-muted">
                        {t("finance.monthEnd.noExpectedCollection")}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-card border border-line bg-surface">
                        <table className="w-full min-w-[900px] text-sm">
                            <thead className="bg-raised text-left text-xs uppercase text-ink-muted">
                                <tr>
                                    {[
                                        t("common.date"),
                                        t("orders.show.order"),
                                        t("common.customer"),
                                        t("finance.monthEnd.planned"),
                                        t("finance.monthEnd.matched"),
                                        t("orders.show.remaining"),
                                        t("common.status"),
                                        t("common.actions"),
                                    ].map((x) => (
                                        <th key={x} className="px-4 py-3">
                                            {x}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {props.expectedCollections.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-4 py-3">
                                            {formatDate(row.expected_date)}
                                        </td>
                                        <td className="px-4 py-3 font-medium">
                                            {row.order_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.customer}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {formatMoney(
                                                row.amount,
                                                row.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {formatMoney(
                                                row.matched,
                                                row.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold">
                                            {formatMoney(
                                                row.remaining,
                                                row.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Status
                                                value={row.status}
                                                label={
                                                    {
                                                        planned:
                                                            t(
                                                                "status.upcoming",
                                                            ),
                                                        partial:
                                                            t("status.partial"),
                                                        received: t(
                                                            "finance.monthEnd.received",
                                                        ),
                                                        overdue:
                                                            t("status.overdue"),
                                                        cancelled:
                                                            t(
                                                                "status.cancelled",
                                                            ),
                                                    }[row.status] ?? row.status
                                                }
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex gap-1">
                                                {can.manage &&
                                                    row.status !==
                                                        "cancelled" &&
                                                    row.status !==
                                                        "received" && (
                                                        <>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => {
                                                                    expectedForm.setData(
                                                                        {
                                                                            sales_order_id:
                                                                                String(
                                                                                    row.sales_order_id,
                                                                                ),
                                                                            label:
                                                                                row.label ??
                                                                                "",
                                                                            reference:
                                                                                row.reference ??
                                                                                "",
                                                                            expected_date:
                                                                                row.expected_date,
                                                                            expected_amount:
                                                                                formatEditableNumber(
                                                                                    row.amount,
                                                                                ),
                                                                            notes:
                                                                                row.notes ??
                                                                                "",
                                                                        },
                                                                    );
                                                                    setExpectedEditing(
                                                                        row,
                                                                    );
                                                                }}
                                                            >
                                                                {t(
                                                                    "common.edit",
                                                                )}
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() => {
                                                                    matchForm.setData(
                                                                        {
                                                                            payment_allocation_id:
                                                                                "",
                                                                            amount: formatEditableNumber(
                                                                                row.remaining,
                                                                            ),
                                                                        },
                                                                    );
                                                                    setExpectedMatching(
                                                                        row,
                                                                    );
                                                                }}
                                                            >
                                                                {t(
                                                                    "finance.monthEnd.match",
                                                                )}
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="danger"
                                                                onClick={() => {
                                                                    expectedCancelForm.setData(
                                                                        "reason",
                                                                        "",
                                                                    );
                                                                    setExpectedCancelling(
                                                                        row,
                                                                    );
                                                                }}
                                                            >
                                                                {t(
                                                                    "common.cancel",
                                                                )}
                                                            </Button>
                                                        </>
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {props.futureObligations.length > 0 && (
                <section className="mt-7">
                    <div className="mb-3">
                        <h2 className="text-lg font-semibold">
                            {t("finance.monthEnd.futureDueDates")}
                        </h2>
                        <p className="text-sm text-ink-muted">
                            {t("finance.monthEnd.futureHelp")}
                        </p>
                    </div>
                    <div className="overflow-hidden rounded-card border border-line bg-surface">
                        <table className="w-full text-sm">
                            <thead className="bg-raised text-left text-xs uppercase text-ink-muted">
                                <tr>
                                    {[
                                        t("common.date"),
                                        t("common.supplier"),
                                        t("common.reference"),
                                        t("common.amount"),
                                        t("common.source"),
                                    ].map((x) => (
                                        <th key={x} className="px-4 py-3">
                                            {x}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {props.futureObligations.map((x) => (
                                    <tr key={x.id}>
                                        <td className="px-4 py-3">
                                            {formatDate(x.due_date)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {x.supplier}
                                        </td>
                                        <td className="px-4 py-3">
                                            {x.reference ?? "—"}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium">
                                            {formatMoney(
                                                x.amount,
                                                x.currency_code,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">
                                            {x.source}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            <Sheet
                open={creating || !!editing}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                title={
                    editing
                        ? `${t("common.edit")} ${editing.number}`
                        : t("finance.monthEnd.addObligation")
                }
                description={t("finance.monthEnd.obligationFormHelp")}
                widthClassName="max-w-2xl"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setCreating(false);
                                setEditing(null);
                            }}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button
                            form="obligation-form"
                            type="submit"
                            loading={obligationForm.processing}
                        >
                            {t("common.save")}
                        </Button>
                    </div>
                }
            >
                <ObligationForm
                    form={obligationForm}
                    stores={stores}
                    suppliers={suppliers}
                    accounts={financialAccounts}
                    categories={categories}
                    methods={paymentMethods}
                    onSubmit={submitObligation}
                />
            </Sheet>

            <Sheet
                open={!!detail}
                onClose={() => setDetail(null)}
                title={detail?.number}
                description={detail?.label}
                widthClassName="max-w-xl"
            >
                {detail && (
                    <div className="space-y-6 p-5">
                        <Detail row={detail} />
                        <section>
                            <h3 className="mb-3 text-sm font-semibold">
                                {t("orders.show.paymentHistory")}
                            </h3>
                            {detail.payments.length === 0 ? (
                                <p className="text-sm text-ink-muted">
                                    {t("orders.show.noPayment")}
                                </p>
                            ) : (
                                <div className="space-y-2">
                                    {detail.payments.map((p) => (
                                        <div
                                            key={p.allocation_id}
                                            className="rounded-field border border-line p-3"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="font-medium">
                                                        {p.number} ·{" "}
                                                        {formatDate(p.date)}
                                                    </p>
                                                    <p className="text-xs text-ink-muted">
                                                        {p.account?.name ?? "—"}{" "}
                                                        ·{" "}
                                                        {paymentMethods.find(
                                                            (m) =>
                                                                m.value ===
                                                                p.method,
                                                        )?.label ?? p.method}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="font-semibold">
                                                        {formatMoney(
                                                            p.amount,
                                                            detail.currency_code,
                                                        )}
                                                    </p>
                                                    <Status
                                                        value={p.status}
                                                        label={
                                                            p.status ===
                                                            "posted"
                                                                ? t(
                                                                      "status.posted",
                                                                  )
                                                                : t(
                                                                      "status.reversed",
                                                                  )
                                                        }
                                                    />
                                                </div>
                                            </div>
                                            {can.pay &&
                                                p.status === "posted" && (
                                                    <Button
                                                        className="mt-2"
                                                        size="sm"
                                                        variant="danger"
                                                        onClick={() => {
                                                            setReversing(p);
                                                            reverseForm.setData(
                                                                "reason",
                                                                "",
                                                            );
                                                        }}
                                                    >
                                                        {t(
                                                            "finance.monthEnd.reverse",
                                                        )}
                                                    </Button>
                                                )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                        <section>
                            <h3 className="mb-3 text-sm font-semibold">
                                {t("finance.monthEnd.instruments")}
                            </h3>
                            {detail.instruments.length === 0 ? (
                                <p className="text-sm text-ink-muted">
                                    {t("finance.monthEnd.noInstrument")}
                                </p>
                            ) : (
                                <div className="space-y-2">
                                    {detail.instruments.map((i) => (
                                        <div
                                            key={i.id}
                                            className="rounded-field border border-line p-3"
                                        >
                                            <div className="flex justify-between gap-3">
                                                <div>
                                                    <p className="font-medium">
                                                        {i.number} ·{" "}
                                                        {i.external_reference}
                                                    </p>
                                                    <p className="text-xs text-ink-muted">
                                                        {i.type === "cheque"
                                                            ? t(
                                                                  "finance.monthEnd.cheque",
                                                              )
                                                            : t(
                                                                  "finance.monthEnd.draftInstrument",
                                                              )}{" "}
                                                        ·{" "}
                                                        {t(
                                                            "finance.monthEnd.dueDate",
                                                        ).toLowerCase()}{" "}
                                                        {formatDate(
                                                            i.maturity_date,
                                                        )}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="font-semibold">
                                                        {formatMoney(
                                                            i.amount,
                                                            detail.currency_code,
                                                        )}
                                                    </p>
                                                    <Status
                                                        value={i.status}
                                                        label={i.status_label}
                                                    />
                                                </div>
                                            </div>
                                            <div className="mt-2 flex gap-2">
                                                {can.manage &&
                                                    i.state === "issued" && (
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/finance/situation-fin-de-mois/instruments/${i.id}/present`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                "finance.monthEnd.present",
                                                            )}
                                                        </Button>
                                                    )}
                                                {can.pay &&
                                                    [
                                                        "issued",
                                                        "presented",
                                                    ].includes(i.state) && (
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/finance/situation-fin-de-mois/instruments/${i.id}/clear`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                "finance.monthEnd.clearInstrument",
                                                            )}
                                                        </Button>
                                                    )}
                                                {can.manage &&
                                                    [
                                                        "issued",
                                                        "presented",
                                                    ].includes(i.state) && (
                                                        <Button
                                                            size="sm"
                                                            variant="danger"
                                                            onClick={() => {
                                                                setCancellingInstrument(
                                                                    i,
                                                                );
                                                                instrumentCancelForm.setData(
                                                                    "reason",
                                                                    "",
                                                                );
                                                            }}
                                                        >
                                                            {t("common.cancel")}
                                                        </Button>
                                                    )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                        <div className="flex flex-wrap gap-2">
                            {can.manage && !detail.cancelled_at && (
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        setDetail(null);
                                        openEdit(detail);
                                    }}
                                >
                                    {t("common.edit")}
                                </Button>
                            )}
                            {can.manage &&
                                !["paid", "cancelled"].includes(
                                    detail.status,
                                ) &&
                                Number(
                                    subtractExact(
                                        detail.remaining_amount,
                                        detail.committed_amount,
                                    ),
                                ) > 0 && (
                                    <Button
                                        variant="secondary"
                                        onClick={() => {
                                            setDetail(null);
                                            openInstrument(detail);
                                        }}
                                    >
                                        {t("finance.monthEnd.schedulePayment")}
                                    </Button>
                                )}
                            {can.pay &&
                                !["paid", "cancelled"].includes(
                                    detail.status,
                                ) &&
                                Number(
                                    subtractExact(
                                        detail.remaining_amount,
                                        detail.committed_amount,
                                    ),
                                ) > 0 && (
                                    <Button
                                        onClick={() => {
                                            setDetail(null);
                                            openPayment(detail);
                                        }}
                                    >
                                        {t("orders.show.recordPayment")}
                                    </Button>
                                )}
                            {can.manage &&
                                !detail.cancelled_at &&
                                Number(detail.paid_amount) === 0 && (
                                    <Button
                                        variant="danger"
                                        onClick={() => {
                                            setCancelling(detail);
                                            cancelForm.setData("reason", "");
                                        }}
                                    >
                                        {t("finance.monthEnd.cancelObligation")}
                                    </Button>
                                )}
                        </div>
                    </div>
                )}
            </Sheet>

            <Sheet
                open={!!paying}
                onClose={() => setPaying(null)}
                title={t("orders.show.recordPayment")}
                description={
                    paying
                        ? `${paying.number} · ${t("orders.show.remaining")} ${formatMoney(paying.remaining_amount, paying.currency_code)}`
                        : undefined
                }
                widthClassName="max-w-xl"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setPaying(null)}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button
                            form="payment-form"
                            type="submit"
                            loading={paymentForm.processing}
                        >
                            Comptabiliser
                        </Button>
                    </div>
                }
            >
                <form
                    id="payment-form"
                    onSubmit={submitPayment}
                    className="grid gap-4 p-5"
                >
                    <Select
                        label={t("finance.monthEnd.financialAccount")}
                        value={paymentForm.data.financial_account_id}
                        onChange={(v) =>
                            paymentForm.setData("financial_account_id", v)
                        }
                        error={paymentForm.errors.financial_account_id}
                    >
                        <option value="">{t("finance.monthEnd.select")}</option>
                        {financialAccounts
                            .filter(
                                (a) =>
                                    a.currency_code ===
                                    paymentForm.data.currency_code,
                            )
                            .map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name} · {a.code}
                                </option>
                            ))}
                    </Select>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Select
                            label="Mode de paiement"
                            value={paymentForm.data.payment_method}
                            onChange={(v) =>
                                paymentForm.setData("payment_method", v)
                            }
                        >
                            {paymentMethods.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </Select>
                        <Field
                            label={t("finance.monthEnd.paymentDate")}
                            type="date"
                            value={paymentForm.data.payment_date}
                            onChange={(v) =>
                                paymentForm.setData("payment_date", v)
                            }
                            error={paymentForm.errors.payment_date}
                        />
                    </div>
                    <Field
                        label={t("common.amount")}
                        type="number"
                        step="0.0001"
                        min="0.0001"
                        value={paymentForm.data.amount}
                        onChange={syncPaymentAmount}
                        error={
                            paymentForm.errors.amount ||
                            paymentForm.errors.allocations
                        }
                    />
                    <Field
                        label={t("common.reference")}
                        value={paymentForm.data.reference}
                        onChange={(v) => paymentForm.setData("reference", v)}
                        error={paymentForm.errors.reference}
                    />
                    <TextArea
                        label={t("common.notes")}
                        value={paymentForm.data.notes}
                        onChange={(v) => paymentForm.setData("notes", v)}
                        error={paymentForm.errors.notes}
                    />
                    <p className="rounded-field bg-sage px-3 py-2 text-xs text-ink-muted">
                        {t("finance.monthEnd.outgoingPaymentHelp")}
                    </p>
                </form>
            </Sheet>

            <ReasonSheet
                title={t("finance.monthEnd.cancelObligation")}
                open={!!cancelling}
                onClose={() => setCancelling(null)}
                form={cancelForm}
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    if (!cancelling) return;
                    cancelForm.post(
                        `/finance/situation-fin-de-mois/obligations/${cancelling.id}/cancel`,
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setCancelling(null);
                                setDetail(null);
                            },
                        },
                    );
                }}
            />
            <ReasonSheet
                title={t("finance.monthEnd.reversePayment")}
                open={!!reversing}
                onClose={() => setReversing(null)}
                form={reverseForm}
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    if (!reversing) return;
                    reverseForm.post(
                        `/finance/situation-fin-de-mois/outgoing-payments/${reversing.payment_id}/reverse`,
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setReversing(null);
                                setDetail(null);
                            },
                        },
                    );
                }}
            />
            <Sheet
                open={!!instrumentFor}
                onClose={() => setInstrumentFor(null)}
                title={t("finance.monthEnd.schedulePayment")}
                description={t("finance.monthEnd.instrumentHelp")}
                widthClassName="max-w-xl"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setInstrumentFor(null)}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button
                            form="instrument-form"
                            type="submit"
                            loading={instrumentForm.processing}
                        >
                            Programmer
                        </Button>
                    </div>
                }
            >
                <form
                    id="instrument-form"
                    className="grid gap-4 p-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!instrumentFor) return;
                        instrumentForm.post(
                            `/finance/situation-fin-de-mois/obligations/${instrumentFor.id}/instruments`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setInstrumentFor(null),
                            },
                        );
                    }}
                >
                    <Select
                        label={t("finance.monthEnd.type")}
                        value={instrumentForm.data.type}
                        onChange={(v) => instrumentForm.setData("type", v)}
                    >
                        <option value="cheque">
                            {t("finance.monthEnd.cheque")}
                        </option>
                        <option value="traite">
                            {t("finance.monthEnd.draftInstrument")}
                        </option>
                    </Select>
                    <Field
                        label={t("finance.monthEnd.numberReference")}
                        value={instrumentForm.data.external_reference}
                        onChange={(v) =>
                            instrumentForm.setData("external_reference", v)
                        }
                        error={instrumentForm.errors.external_reference}
                    />
                    <Field
                        label={t("common.amount")}
                        type="number"
                        step="0.0001"
                        min="0.0001"
                        value={instrumentForm.data.amount}
                        onChange={(v) => instrumentForm.setData("amount", v)}
                        error={instrumentForm.errors.amount}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t("finance.monthEnd.issueDate")}
                            type="date"
                            value={instrumentForm.data.issue_date}
                            onChange={(v) =>
                                instrumentForm.setData("issue_date", v)
                            }
                        />
                        <Field
                            label={t("finance.monthEnd.dueDate")}
                            type="date"
                            value={instrumentForm.data.maturity_date}
                            onChange={(v) =>
                                instrumentForm.setData("maturity_date", v)
                            }
                            error={instrumentForm.errors.maturity_date}
                        />
                    </div>
                    <Select
                        label={t("finance.monthEnd.financialAccount")}
                        value={instrumentForm.data.financial_account_id}
                        onChange={(v) =>
                            instrumentForm.setData("financial_account_id", v)
                        }
                        error={instrumentForm.errors.financial_account_id}
                    >
                        <option value="">{t("finance.monthEnd.select")}</option>
                        {financialAccounts
                            .filter(
                                (a) =>
                                    a.currency_code ===
                                    instrumentFor?.currency_code,
                            )
                            .map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                    </Select>
                    <TextArea
                        label={t("common.notes")}
                        value={instrumentForm.data.notes}
                        onChange={(v) => instrumentForm.setData("notes", v)}
                    />
                </form>
            </Sheet>
            <ReasonSheet
                title={t("finance.monthEnd.cancelInstrument")}
                open={!!cancellingInstrument}
                onClose={() => setCancellingInstrument(null)}
                form={instrumentCancelForm}
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    if (!cancellingInstrument) return;
                    instrumentCancelForm.post(
                        `/finance/situation-fin-de-mois/instruments/${cancellingInstrument.id}/cancel`,
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setCancellingInstrument(null);
                                setDetail(null);
                            },
                        },
                    );
                }}
            />
            <Sheet
                open={expectedCreating || !!expectedEditing}
                onClose={() => {
                    setExpectedCreating(false);
                    setExpectedEditing(null);
                }}
                title={
                    expectedEditing
                        ? t("finance.monthEnd.editExpectedCollection")
                        : t("finance.monthEnd.planCollection")
                }
                description={t("finance.monthEnd.expectedHelp")}
                widthClassName="max-w-xl"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setExpectedCreating(false);
                                setExpectedEditing(null);
                            }}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button
                            form="expected-form"
                            type="submit"
                            loading={expectedForm.processing}
                        >
                            {t("common.save")}
                        </Button>
                    </div>
                }
            >
                <form
                    id="expected-form"
                    className="grid gap-4 p-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => {
                                setExpectedCreating(false);
                                setExpectedEditing(null);
                            },
                        };
                        expectedEditing
                            ? expectedForm.patch(
                                  `/finance/situation-fin-de-mois/expected-collections/${expectedEditing.id}`,
                                  options,
                              )
                            : expectedForm.post(
                                  "/finance/situation-fin-de-mois/expected-collections",
                                  options,
                              );
                    }}
                >
                    <Select
                        label={t("finance.monthEnd.orderReceivable")}
                        value={expectedForm.data.sales_order_id}
                        onChange={(v) => {
                            expectedForm.setData("sales_order_id", v);
                            const order = props.receivableOrders.find(
                                (o) => String(o.id) === v,
                            );
                            if (order)
                                expectedForm.setData(
                                    "expected_amount",
                                    formatEditableNumber(order.outstanding),
                                );
                        }}
                        error={expectedForm.errors.sales_order_id}
                    >
                        <option value="">{t("finance.monthEnd.select")}</option>
                        {props.receivableOrders.map((order) => (
                            <option key={order.id} value={order.id}>
                                {order.number} · {order.customer} · reste{" "}
                                {formatMoney(
                                    order.outstanding,
                                    order.currency_code,
                                )}
                            </option>
                        ))}
                    </Select>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t("finance.monthEnd.expectedDate")}
                            type="date"
                            value={expectedForm.data.expected_date}
                            onChange={(v) =>
                                expectedForm.setData("expected_date", v)
                            }
                            error={expectedForm.errors.expected_date}
                        />
                        <Field
                            label={t("finance.monthEnd.expectedAmount")}
                            type="number"
                            min="0.0001"
                            step="0.0001"
                            value={expectedForm.data.expected_amount}
                            onChange={(v) =>
                                expectedForm.setData("expected_amount", v)
                            }
                            error={expectedForm.errors.expected_amount}
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t("finance.monthEnd.label")}
                            value={expectedForm.data.label}
                            onChange={(v) => expectedForm.setData("label", v)}
                        />
                        <Field
                            label={t("common.reference")}
                            value={expectedForm.data.reference}
                            onChange={(v) =>
                                expectedForm.setData("reference", v)
                            }
                        />
                    </div>
                    <TextArea
                        label={t("common.notes")}
                        value={expectedForm.data.notes}
                        onChange={(v) => expectedForm.setData("notes", v)}
                    />
                </form>
            </Sheet>
            <ReasonSheet
                title={t("finance.monthEnd.cancelExpected")}
                open={!!expectedCancelling}
                onClose={() => setExpectedCancelling(null)}
                form={expectedCancelForm}
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    if (!expectedCancelling) return;
                    expectedCancelForm.post(
                        `/finance/situation-fin-de-mois/expected-collections/${expectedCancelling.id}/cancel`,
                        {
                            preserveScroll: true,
                            onSuccess: () => setExpectedCancelling(null),
                        },
                    );
                }}
            />
            <Sheet
                open={!!expectedMatching}
                onClose={() => setExpectedMatching(null)}
                title={t("finance.monthEnd.matchCollection")}
                description={t("finance.monthEnd.matchHelp")}
                widthClassName="max-w-lg"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setExpectedMatching(null)}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button
                            form="match-form"
                            type="submit"
                            loading={matchForm.processing}
                        >
                            {t("finance.monthEnd.match")}
                        </Button>
                    </div>
                }
            >
                <form
                    id="match-form"
                    className="grid gap-4 p-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!expectedMatching) return;
                        matchForm.post(
                            `/finance/situation-fin-de-mois/expected-collections/${expectedMatching.id}/match`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setExpectedMatching(null),
                            },
                        );
                    }}
                >
                    <Select
                        label={t("finance.monthEnd.receivedPayment")}
                        value={matchForm.data.payment_allocation_id}
                        onChange={(v) => {
                            matchForm.setData("payment_allocation_id", v);
                            const option =
                                props.collectionAllocationOptions.find(
                                    (x) => String(x.id) === v,
                                );
                            if (option && expectedMatching)
                                matchForm.setData(
                                    "amount",
                                    formatEditableNumber(
                                        Number(option.available) <
                                            Number(expectedMatching.remaining)
                                            ? option.available
                                            : expectedMatching.remaining,
                                    ),
                                );
                        }}
                        error={matchForm.errors.payment_allocation_id}
                    >
                        <option value="">{t("finance.monthEnd.select")}</option>
                        {props.collectionAllocationOptions
                            .filter(
                                (x) =>
                                    x.sales_order_id ===
                                    expectedMatching?.sales_order_id,
                            )
                            .map((x) => (
                                <option key={x.id} value={x.id}>
                                    {x.number} · {formatDate(x.date)} ·
                                    disponible{" "}
                                    {formatMoney(x.available, currencyCode)}
                                </option>
                            ))}
                    </Select>
                    <Field
                        label={t("finance.monthEnd.matchedAmount")}
                        type="number"
                        min="0.0001"
                        step="0.0001"
                        value={matchForm.data.amount}
                        onChange={(v) => matchForm.setData("amount", v)}
                        error={matchForm.errors.amount}
                    />
                </form>
            </Sheet>
            <Sheet
                open={closingMonth}
                onClose={() => setClosingMonth(false)}
                title={`${t("finance.monthEnd.close")} ${props.periodLabel}`}
                description={t("finance.monthEnd.closeHelp")}
                widthClassName="max-w-lg"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setClosingMonth(false)}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button
                            form="close-month-form"
                            type="submit"
                            loading={closeForm.processing}
                        >
                            {t("finance.monthEnd.close")}
                        </Button>
                    </div>
                }
            >
                <form
                    id="close-month-form"
                    className="grid gap-4 p-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        closeForm.post("/finance/situation-fin-de-mois/close", {
                            preserveScroll: true,
                            onSuccess: () => setClosingMonth(false),
                        });
                    }}
                >
                    <TextArea
                        label="Note (facultative)"
                        value={closeForm.data.note}
                        onChange={(v) => closeForm.setData("note", v)}
                        error={closeForm.errors.note}
                    />
                    {props.monthReview.warnings.length > 0 && (
                        <label className="flex items-start gap-2 rounded-field bg-warning-soft p-3 text-sm text-warning">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={closeForm.data.acknowledge_warnings}
                                onChange={(e) =>
                                    closeForm.setData(
                                        "acknowledge_warnings",
                                        e.target.checked,
                                    )
                                }
                            />
                            <span>
                                J’ai pris connaissance des avertissements et je
                                {t("finance.monthEnd.acknowledgeWarnings")}
                            </span>
                        </label>
                    )}
                    {closeForm.errors.acknowledge_warnings && (
                        <p className="text-xs text-danger">
                            {closeForm.errors.acknowledge_warnings}
                        </p>
                    )}
                    <p className="text-xs text-ink-muted">
                        {t("finance.monthEnd.closeSummaryHelp")}
                    </p>
                </form>
            </Sheet>
            <Sheet
                open={reopeningMonth}
                onClose={() => setReopeningMonth(false)}
                title={`${t("finance.monthEnd.reopen")} ${props.periodLabel}`}
                description={t("finance.monthEnd.reopenHelp")}
                widthClassName="max-w-lg"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setReopeningMonth(false)}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button
                            form="reopen-month-form"
                            type="submit"
                            loading={reopenForm.processing}
                        >
                            {t("finance.monthEnd.reopen")}
                        </Button>
                    </div>
                }
            >
                <form
                    id="reopen-month-form"
                    className="p-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        reopenForm.post(
                            "/finance/situation-fin-de-mois/reopen",
                            {
                                preserveScroll: true,
                                onSuccess: () => setReopeningMonth(false),
                            },
                        );
                    }}
                >
                    <TextArea
                        label={t("finance.monthEnd.reasonRequired")}
                        value={reopenForm.data.reason}
                        onChange={(v) => reopenForm.setData("reason", v)}
                        error={reopenForm.errors.reason}
                    />
                </form>
            </Sheet>
        </ApplicationShell>
    );
}

function Metric({
    label,
    value,
    hint,
    strong,
    tone = "default",
}: {
    label: string;
    value: string;
    hint?: string;
    strong?: boolean;
    tone?: "default" | "danger";
}) {
    return (
        <div
            className={`rounded-card border p-4 ${tone === "danger" ? "border-danger/25 bg-danger-soft" : "border-line bg-surface"}`}
        >
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">
                {label}
            </p>
            <p
                className={`mt-2 text-xl text-ink ${strong ? "font-bold" : "font-semibold"}`}
            >
                {value}
            </p>
            {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
        </div>
    );
}
function Status({ value, label }: { value: string; label: string }) {
    const tone =
        value === "paid" || value === "posted"
            ? "bg-success-soft text-success"
            : value === "overdue" || value === "reversed"
              ? "bg-danger-soft text-danger"
              : value === "partial"
                ? "bg-warning-soft text-warning"
                : "bg-raised text-ink-muted";
    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${tone}`}
        >
            {label}
        </span>
    );
}
function ReviewStatus({ status }: { status: MonthReview["status"] }) {
    const { t } = useI18n();
    const label = {
        open: t("finance.monthEnd.reviewOpen"),
        reviewed: t("finance.monthEnd.reviewed"),
        closed: t("finance.monthEnd.reviewClosed"),
    }[status];
    const tone =
        status === "closed"
            ? "bg-success-soft text-success"
            : status === "reviewed"
              ? "bg-sage text-primary"
              : "bg-raised text-ink-muted";
    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${tone}`}
        >
            {label}
        </span>
    );
}
function Label({ children }: { children: ReactNode }) {
    return (
        <span className="mb-1.5 block text-[13px] font-medium text-ink">
            {children}
        </span>
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
    onChange: (v: string) => void;
    error?: string;
} & Omit<InputHTMLAttributes<HTMLInputElement>, "value" | "onChange">) {
    return (
        <label className="block">
            <Label>{label}</Label>
            <input
                {...rest}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={inputClass}
            />
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
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
    onChange: (v: string) => void;
    error?: string;
    children: ReactNode;
}) {
    return (
        <label className="block">
            <Label>{label}</Label>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={selectClass}
            >
                {children}
            </select>
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </label>
    );
}
function TextArea({
    label,
    value,
    onChange,
    error,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    return (
        <label className="block">
            <Label>{label}</Label>
            <textarea
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={textareaClass}
            />
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </label>
    );
}

function ObligationForm({
    form,
    stores,
    suppliers,
    accounts,
    categories,
    methods,
    onSubmit,
}: any) {
    const { t } = useI18n();

    return (
        <form
            id="obligation-form"
            onSubmit={onSubmit}
            className="grid gap-4 p-5"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Select
                    label={t("finance.monthEnd.type")}
                    value={form.data.category}
                    onChange={(v) => form.setData("category", v)}
                    error={form.errors.category}
                >
                    {categories.map((o: Option) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </Select>
                <Field
                    label={t("finance.monthEnd.label")}
                    value={form.data.label}
                    onChange={(v) => form.setData("label", v)}
                    error={form.errors.label}
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Select
                    label={t("finance.monthEnd.optionalStore")}
                    value={form.data.store_id}
                    onChange={(v) => form.setData("store_id", v)}
                    error={form.errors.store_id}
                >
                    <option value="">
                        {t("finance.monthEnd.wholeOrganization")}
                    </option>
                    {stores.map((s: Entity) => (
                        <option key={s.id} value={s.id}>
                            {s.name} · {s.code}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t("finance.monthEnd.optionalSupplier")}
                    value={form.data.supplier_id}
                    onChange={(v) => form.setData("supplier_id", v)}
                    error={form.errors.supplier_id}
                >
                    <option value="">{t("finance.monthEnd.none")}</option>
                    {suppliers.map((s: Entity) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label={t("finance.monthEnd.freeBeneficiary")}
                    value={form.data.beneficiary_name}
                    onChange={(v) => form.setData("beneficiary_name", v)}
                    error={form.errors.beneficiary_name}
                />
                <Field
                    label={t("common.reference")}
                    value={form.data.reference}
                    onChange={(v) => form.setData("reference", v)}
                    error={form.errors.reference}
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label={t("finance.monthEnd.dueDate")}
                    type="date"
                    value={form.data.due_date}
                    onChange={(v) => form.setData("due_date", v)}
                    error={form.errors.due_date}
                />
                <Field
                    label={t("finance.monthEnd.plannedAmount")}
                    type="number"
                    min="0.0001"
                    step="0.0001"
                    value={form.data.planned_amount}
                    onChange={(v) => form.setData("planned_amount", v)}
                    error={form.errors.planned_amount}
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Select
                    label={t("finance.monthEnd.optionalMethod")}
                    value={form.data.planned_payment_method}
                    onChange={(v) => form.setData("planned_payment_method", v)}
                    error={form.errors.planned_payment_method}
                >
                    <option value="">{t("finance.monthEnd.undefined")}</option>
                    {methods.map((m: Option) => (
                        <option key={m.value} value={m.value}>
                            {m.label}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t("finance.monthEnd.optionalPreferredAccount")}
                    value={form.data.preferred_financial_account_id}
                    onChange={(v) =>
                        form.setData("preferred_financial_account_id", v)
                    }
                    error={form.errors.preferred_financial_account_id}
                >
                    <option value="">{t("finance.monthEnd.undefined")}</option>
                    {accounts.map((a: Entity) => (
                        <option key={a.id} value={a.id}>
                            {a.name} · {a.code}
                        </option>
                    ))}
                </Select>
            </div>
            <TextArea
                label={t("common.notes")}
                value={form.data.notes}
                onChange={(v) => form.setData("notes", v)}
                error={form.errors.notes}
            />
        </form>
    );
}
function Detail({ row }: { row: Obligation }) {
    const { t } = useI18n();
    const lines = useMemo(
        () => [
            [t("finance.monthEnd.dueDate"), formatDate(row.due_date)],
            [t("finance.monthEnd.type"), row.category_label],
            [t("finance.monthEnd.beneficiary"), row.beneficiary],
            [t("common.reference"), row.reference ?? "—"],
            [
                t("finance.monthEnd.store"),
                row.store?.name ?? t("finance.monthEnd.wholeOrganization"),
            ],
            [t("finance.monthEnd.createdBy"), row.created_by ?? "—"],
            [
                t("finance.monthEnd.createdOn"),
                row.created_at ? formatDateTime(row.created_at) : "—",
            ],
        ],
        [row, t],
    );
    return (
        <>
            <div className="grid grid-cols-3 gap-3">
                <Metric
                    label={t("finance.monthEnd.planned")}
                    value={formatMoney(row.planned_amount, row.currency_code)}
                />
                <Metric
                    label={t("orders.show.paid")}
                    value={formatMoney(row.paid_amount, row.currency_code)}
                />
                <Metric
                    label={t("finance.monthEnd.remaining")}
                    value={formatMoney(row.remaining_amount, row.currency_code)}
                    strong
                />
            </div>
            <dl className="divide-y divide-line rounded-field border border-line">
                {lines.map(([k, v]) => (
                    <div
                        key={k}
                        className="grid grid-cols-[140px_1fr] gap-3 px-3 py-2 text-sm"
                    >
                        <dt className="text-ink-muted">{k}</dt>
                        <dd className="text-ink">{v}</dd>
                    </div>
                ))}
            </dl>
            {row.notes && (
                <div>
                    <h3 className="text-sm font-semibold">
                        {t("common.notes")}
                    </h3>
                    <p className="mt-1 whitespace-pre-wrap text-sm text-ink-muted">
                        {row.notes}
                    </p>
                </div>
            )}
            {row.cancelled_at && (
                <div className="rounded-field bg-danger-soft p-3 text-sm text-danger">
                    {t("finance.monthEnd.cancelledBy")}{" "}
                    {row.cancelled_by ?? "—"} : {row.cancellation_reason}
                </div>
            )}
        </>
    );
}
function ReasonSheet({ title, open, onClose, form, onSubmit }: any) {
    const { t } = useI18n();
    return (
        <Sheet
            open={open}
            onClose={onClose}
            title={title}
            description={t("finance.monthEnd.historyPreserved")}
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>
                        {t("common.back")}
                    </Button>
                    <Button
                        form="reason-form"
                        type="submit"
                        variant="danger"
                        loading={form.processing}
                    >
                        {t("common.confirm")}
                    </Button>
                </div>
            }
        >
            <form id="reason-form" onSubmit={onSubmit} className="p-5">
                <TextArea
                    label={t("finance.monthEnd.reasonRequired")}
                    value={form.data.reason}
                    onChange={(v) => form.setData("reason", v)}
                    error={
                        form.errors.reason ||
                        form.errors.obligation ||
                        form.errors.payment
                    }
                />
            </form>
        </Sheet>
    );
}
