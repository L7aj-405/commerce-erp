import { Button } from "@/components/ui/Button";
import { Dialog } from "@/components/ui/Dialog";
import StatusBadge, { type StatusVariant } from "@/components/ui/StatusBadge";
import { useI18n } from "@/i18n";
import type { TranslationKey } from "@/i18n/translations";
import { formatQuantity } from "@/utils/format";
import { router } from "@inertiajs/react";
import { useEffect, useId, useState } from "react";

/**
 * Internal supplier-sourcing UI for draft Devis lines. Everything here is
 * ERP-internal: it is never part of the customer PDF.
 */

export type SourcingState =
    | "in_stock"
    | "covered"
    | "to_source"
    | "pending"
    | "available"
    | "partial"
    | "unavailable"
    | "confirmed_manually"
    | "insufficient";

export type SourcingRequest = {
    id: number;
    supplier_id: number | null;
    supplier_name: string | null;
    status:
        | "pending"
        | "available"
        | "partial"
        | "unavailable"
        | "confirmed_manually";
    requested_quantity: string;
    confirmed_quantity: string | null;
    note: string | null;
    requested_at: string | null;
    requested_by: string | null;
    resolved_at: string | null;
    resolved_by: string | null;
};

export type LineSourcing = {
    line_id: number;
    line_type: "catalog" | "non_stock";
    article: string;
    reference: string | null;
    requested_quantity: string;
    internal_available: string | null;
    shortage: string;
    covered_quantity: string;
    supplier_confirmed_quantity: string;
    manually_confirmed_quantity: string;
    requires_sourcing: boolean;
    resolved: boolean;
    state: SourcingState;
    requests: SourcingRequest[];
};

export type SourcingCan = {
    request: boolean;
    record: boolean;
};

type SupplierOption = {
    id: number;
    name: string;
    phone: string | null;
    known: boolean;
};

const STATE: Record<SourcingState, [TranslationKey, StatusVariant]> = {
    in_stock: ["sourcing.inStock", "success"],
    covered: ["sourcing.available", "success"],
    available: ["sourcing.available", "success"],
    confirmed_manually: ["sourcing.confirmedManually", "neutral"],
    to_source: ["sourcing.toSource", "warning"],
    pending: ["sourcing.pending", "info"],
    partial: ["sourcing.partial", "warning"],
    insufficient: ["sourcing.insufficient", "warning"],
    unavailable: ["sourcing.unavailable", "danger"],
};

const REQUEST_STATUS: Record<SourcingRequest["status"], TranslationKey> = {
    pending: "sourcing.pending",
    available: "sourcing.available",
    partial: "sourcing.partial",
    unavailable: "sourcing.unavailable",
    confirmed_manually: "sourcing.confirmedManually",
};

const RELOAD = ["sourcing", "errors", "flash"];

/**
 * Cancel through the canonical sourcing route. Shared by the Devis editor and
 * the Achats › Demandes fournisseur inbox (which reloads different props).
 */
export function cancelSupplierRequest(
    quotationId: number,
    requestId: number,
    options: { only?: string[]; onFinish?: () => void } = {},
) {
    router.post(
        `/quotations/${quotationId}/supplier-requests/${requestId}/cancel`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: options.only ?? RELOAD,
            onFinish: options.onFinish,
        },
    );
}

/** "3.0000" → "3" for editable inputs (server expects a dot decimal). */
function plainNumber(value: string): string {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? String(parsed) : value;
}

export function SourcingBadge({ state }: { state: SourcingState }) {
    const { t } = useI18n();
    const [label, variant] = STATE[state];

    return (
        <StatusBadge variant={variant} className="!px-2 !py-0.5 !text-[11px]">
            {t(label)}
        </StatusBadge>
    );
}

/** Soft, semantic panel tone per sourcing state (color is never the only signal). */
const TONE: Record<SourcingState, string> = {
    in_stock: "border-success/25 bg-success-soft/55",
    covered: "border-success/25 bg-success-soft/55",
    available: "border-success/25 bg-success-soft/55",
    to_source: "border-warning/25 bg-warning-soft/55",
    partial: "border-warning/25 bg-warning-soft/55",
    insufficient: "border-warning/25 bg-warning-soft/55",
    pending: "border-info/20 bg-info-soft/55",
    unavailable: "border-danger/25 bg-danger-soft/55",
    confirmed_manually: "border-line-strong bg-raised",
};

const HELPER: Partial<Record<SourcingState, TranslationKey>> = {
    in_stock: "sourcing.helperInStock",
    to_source: "sourcing.helperToSource",
    pending: "sourcing.helperPending",
    available: "sourcing.helperAvailable",
    partial: "sourcing.helperPartial",
    unavailable: "sourcing.helperUnavailable",
    insufficient: "sourcing.helperInsufficient",
    confirmed_manually: "sourcing.manualConfirmation",
};

type MetricTone = "neutral" | "info" | "warning" | "success";

const METRIC_TONE: Record<MetricTone, { box: string; value: string }> = {
    neutral: { box: "border-line/80 bg-surface/85", value: "text-ink" },
    info: { box: "border-info/20 bg-info-soft/60", value: "text-info" },
    warning: { box: "border-warning/30 bg-warning-soft/70", value: "text-warning" },
    success: { box: "border-success/25 bg-success-soft/70", value: "text-success" },
};

function Metric({
    label,
    value,
    tone = "neutral",
}: {
    label: string;
    value: string;
    tone?: MetricTone;
}) {
    const styles = METRIC_TONE[tone];

    return (
        <div className={`min-w-0 rounded-field border px-2.5 py-2 ${styles.box}`}>
            {/* Labels wrap (up to 2 lines) instead of being cut with an ellipsis. */}
            <dt className="text-[11px] leading-4 whitespace-normal break-words text-ink-muted">
                {label}
            </dt>
            <dd className={`mt-0.5 text-base font-semibold leading-6 tabular-nums ${styles.value}`}>
                {value}
            </dd>
        </div>
    );
}

function TruckIcon() {
    return (
        <svg
            width="15"
            height="15"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M3 7h11v9H3zM14 10h4l3 3v3h-7" />
            <circle cx="7" cy="17.5" r="1.5" />
            <circle cx="17" cy="17.5" r="1.5" />
        </svg>
    );
}

/**
 * Full-width internal sourcing panel rendered under a commercial line:
 * status → metrics → action. Purely presentational over the server-provided
 * sourcing evaluation; it computes nothing that decides the issue gate.
 */
export function SourcingPanel({
    quotationId,
    sourcing,
    can,
}: {
    quotationId: number;
    sourcing: LineSourcing;
    can: SourcingCan;
}) {
    const { t } = useI18n();
    const detailsId = useId();
    const [requestOpen, setRequestOpen] = useState(false);
    const [expanded, setExpanded] = useState(false);

    const isCatalog = sourcing.line_type === "catalog";
    const supplierRequests = sourcing.requests.filter(
        (r) => r.status !== "confirmed_manually",
    );
    const supplierNames = [
        ...new Set(
            supplierRequests
                .map((r) => r.supplier_name)
                .filter((n): n is string => Boolean(n)),
        ),
    ];
    const hasRequests = sourcing.requests.length > 0;
    const canCreate =
        sourcing.requires_sourcing && !sourcing.resolved && can.request;
    const shortageOpen = Number(sourcing.shortage) > 0;
    // Display only: what is still uncovered (the gate itself is server-side).
    const remaining = Math.max(
        0,
        Number(sourcing.shortage) - Number(sourcing.covered_quantity),
    );
    const helper = HELPER[sourcing.state];
    const showConfirmed = sourcing.requires_sourcing && hasRequests;
    const showRemaining = showConfirmed && !sourcing.resolved;
    const metricCount = 3 + Number(showConfirmed) + Number(showRemaining);
    // 2 columns when narrow (a final odd card spans the row), then 3, then all
    // in one row once the panel is wide enough. Never a single cramped row.
    const metricCols = {
        3: "grid-cols-2 [&>*:last-child]:col-span-2 @md:grid-cols-3 @md:[&>*:last-child]:col-span-1",
        4: "grid-cols-2 @3xl:grid-cols-4",
        5: "grid-cols-2 [&>*:last-child]:col-span-2 @xl:grid-cols-3 @xl:[&>*:last-child]:col-span-1 @4xl:grid-cols-5",
    }[metricCount];
    const missingOpen = shortageOpen && !sourcing.resolved;

    return (
        <section
            aria-label={`${t("sourcing.panelLabel")} — ${sourcing.article}`}
            className={`@container rounded-card border px-3 py-2.5 text-xs ${TONE[sourcing.state]}`}
        >
            <div className="grid gap-2.5 @3xl:grid-cols-[minmax(0,1fr)_auto] @3xl:gap-x-4 @6xl:grid-cols-[15rem_minmax(0,1fr)_auto] @6xl:items-center">
                {/* Status */}
                <div className="min-w-0 @3xl:col-start-1 @3xl:row-start-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <SourcingBadge state={sourcing.state} />
                        <span className="text-[10px] uppercase tracking-wide text-ink-faint">
                            {t("sourcing.internal")}
                        </span>
                    </div>
                    {helper && (
                        <p className="mt-1 line-clamp-2 text-ink-muted">
                            {sourcing.state === "to_source" && !isCatalog
                                ? t("sourcing.helperToSourceNonStock")
                                : t(helper)}
                            {supplierNames.length > 0 &&
                                sourcing.state !== "to_source" &&
                                sourcing.state !== "in_stock" &&
                                ` · ${supplierNames.join(", ")}`}
                        </p>
                    )}
                </div>

                {/* Metrics */}
                <dl
                    className={`grid min-w-0 gap-1.5 @md:gap-2 @3xl:col-span-2 @3xl:row-start-2 @6xl:col-span-1 @6xl:col-start-2 @6xl:row-start-1 ${metricCols}`}
                >
                    {isCatalog ? (
                        <Metric
                            label={t("sourcing.internalStock")}
                            value={formatQuantity(sourcing.internal_available)}
                        />
                    ) : (
                        <Metric
                            label={t("sourcing.internalStock")}
                            value={t("quotations.nonStock")}
                        />
                    )}
                    <Metric
                        label={t("sourcing.requestedQuantity")}
                        value={formatQuantity(sourcing.requested_quantity)}
                        tone="info"
                    />
                    <Metric
                        label={
                            isCatalog
                                ? t("sourcing.missingQuantity")
                                : t("sourcing.toSourceQuantity")
                        }
                        value={formatQuantity(sourcing.shortage)}
                        tone={missingOpen ? "warning" : "neutral"}
                    />
                    {showConfirmed && (
                        <Metric
                            label={t("sourcing.confirmedTotal")}
                            value={formatQuantity(sourcing.covered_quantity)}
                            tone={sourcing.resolved ? "success" : "neutral"}
                        />
                    )}
                    {showRemaining && (
                        <Metric
                            label={t("sourcing.remaining")}
                            value={formatQuantity(remaining)}
                            tone={remaining > 0 ? "warning" : "success"}
                        />
                    )}
                </dl>

                {/* Actions */}
                <div className="flex flex-col gap-1.5 @md:flex-row @md:flex-wrap @md:items-center @3xl:col-start-2 @3xl:row-start-1 @3xl:justify-end @3xl:self-start @6xl:col-start-3 @6xl:self-center">
                    {sourcing.state === "in_stock" && (
                        <span className="inline-flex items-center gap-1 font-medium text-success">
                            <span aria-hidden="true">✓</span>
                            {t("sourcing.inStockAvailable")}
                        </span>
                    )}
                    {canCreate && (
                        <Button
                            size="sm"
                            variant={hasRequests ? "secondary" : "primary"}
                            onClick={() => setRequestOpen(true)}
                            className="w-full whitespace-nowrap @md:w-auto"
                        >
                            <TruckIcon />
                            {hasRequests
                                ? t("sourcing.newRequest")
                                : t("sourcing.createRequest")}
                        </Button>
                    )}
                    {hasRequests && (
                        <Button
                            size="sm"
                            variant={canCreate ? "ghost" : "secondary"}
                            onClick={() => setExpanded((v) => !v)}
                            aria-expanded={expanded}
                            aria-controls={detailsId}
                            className="w-full whitespace-nowrap @md:w-auto"
                        >
                            {expanded
                                ? t("sourcing.hideDetails")
                                : sourcing.requests.length > 1
                                  ? `${t("sourcing.viewRequests")} (${sourcing.requests.length})`
                                  : t("sourcing.viewRequest")}
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                aria-hidden="true"
                                className={`transition-transform ${expanded ? "rotate-180" : ""}`}
                            >
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </Button>
                    )}
                </div>
            </div>

            {expanded && (
                <ul
                    id={detailsId}
                    className="mt-2.5 divide-y divide-line/70 rounded-field border border-line/80 bg-surface/80"
                >
                    {sourcing.requests.map((r) => (
                        <RequestRow
                            key={r.id}
                            quotationId={quotationId}
                            request={r}
                            can={can}
                        />
                    ))}
                </ul>
            )}
            {requestOpen && (
                <SupplierRequestDialog
                    quotationId={quotationId}
                    sourcing={sourcing}
                    onClose={() => setRequestOpen(false)}
                />
            )}
        </section>
    );
}

function RequestRow({
    quotationId,
    request,
    can,
}: {
    quotationId: number;
    request: SourcingRequest;
    can: SourcingCan;
}) {
    const { t } = useI18n();
    const [recordOpen, setRecordOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const isManual = request.status === "confirmed_manually";

    const cancel = () => {
        setBusy(true);
        cancelSupplierRequest(quotationId, request.id, {
            onFinish: () => setBusy(false),
        });
    };

    // A manual attestation is an ERP user's statement, never a supplier answer.
    if (isManual) {
        return (
            <li className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-ink-muted">
                <StatusBadge variant="neutral" className="!px-2 !py-0.5 !text-[11px]">
                    {t("sourcing.manualConfirmation")}
                </StatusBadge>
                <span>
                    {t("sourcing.confirmed")}{" "}
                    <span className="font-semibold tabular-nums text-ink">
                        {formatQuantity(request.confirmed_quantity)}
                    </span>
                </span>
                {request.supplier_name && <span>{request.supplier_name}</span>}
                {request.resolved_by && (
                    <span className="text-ink-faint">{request.resolved_by}</span>
                )}
            </li>
        );
    }

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-ink-muted">
            <span className="min-w-0 break-words font-medium text-ink">
                {request.supplier_name ?? t("sourcing.supplierUnspecified")}
            </span>
            <span>
                {t("sourcing.requested")}{" "}
                <span className="font-semibold tabular-nums text-ink">
                    {formatQuantity(request.requested_quantity)}
                </span>
            </span>
            {request.confirmed_quantity !== null && (
                <span>
                    {t("sourcing.confirmed")}{" "}
                    <span className="font-semibold tabular-nums text-ink">
                        {formatQuantity(request.confirmed_quantity)}
                    </span>
                </span>
            )}
            <StatusBadge
                variant={
                    request.status === "available"
                        ? "success"
                        : request.status === "unavailable"
                          ? "danger"
                          : request.status === "partial"
                            ? "warning"
                            : "info"
                }
                className="!px-2 !py-0.5 !text-[11px]"
            >
                {t(REQUEST_STATUS[request.status])}
            </StatusBadge>
            {request.resolved_by && (
                <span className="text-ink-faint">{request.resolved_by}</span>
            )}
            <span className="ms-auto flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
                {can.record && (
                    <button
                        type="button"
                        onClick={() => setRecordOpen(true)}
                        className="rounded-field font-medium text-primary hover:underline"
                    >
                        {t("sourcing.recordResult")}
                    </button>
                )}
                {(can.request || can.record) && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={cancel}
                        className="rounded-field text-ink-faint hover:text-danger disabled:opacity-40"
                    >
                        {t("sourcing.cancelRequest")}
                    </button>
                )}
            </span>
            {request.note && (
                <span className="basis-full italic text-ink-faint">
                    {request.note}
                </span>
            )}
            {recordOpen && (
                <RecordResultDialog
                    quotationId={quotationId}
                    request={request}
                    onClose={() => setRecordOpen(false)}
                />
            )}
        </li>
    );
}

function SupplierRequestDialog({
    quotationId,
    sourcing,
    onClose,
}: {
    quotationId: number;
    sourcing: LineSourcing;
    onClose: () => void;
}) {
    const { t } = useI18n();
    const [search, setSearch] = useState("");
    const [options, setOptions] = useState<SupplierOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [supplierId, setSupplierId] = useState<number | null>(null);
    const [quantity, setQuantity] = useState(
        plainNumber(sourcing.shortage),
    );
    const [note, setNote] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        const ctrl = new AbortController();
        const h = window.setTimeout(async () => {
            setLoading(true);
            try {
                const url = new URL(
                    `/quotations/${quotationId}/lines/${sourcing.line_id}/supplier-options`,
                    window.location.origin,
                );
                if (search.trim() !== "")
                    url.searchParams.set("search", search.trim());
                const res = await fetch(url.toString(), {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    signal: ctrl.signal,
                });
                if (res.ok) {
                    const rows = ((await res.json()) as {
                        data: SupplierOption[];
                    }).data;
                    setOptions(rows);
                    setSupplierId(
                        (cur) =>
                            cur ?? rows.find((r) => r.known)?.id ?? null,
                    );
                }
            } catch {
                /* aborted */
            } finally {
                setLoading(false);
            }
        }, 250);
        return () => {
            ctrl.abort();
            window.clearTimeout(h);
        };
    }, [search, quotationId, sourcing.line_id]);

    const submit = () => {
        if (supplierId === null) return;
        setProcessing(true);
        router.post(
            `/quotations/${quotationId}/lines/${sourcing.line_id}/supplier-requests`,
            {
                supplier_id: supplierId,
                requested_quantity: quantity.trim() || null,
                note: note.trim() || null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: RELOAD,
                onError: (e) => setErrors(e),
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open
            onClose={onClose}
            title={t("sourcing.createRequest")}
            description={t("sourcing.requestHint")}
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>
                        {t("common.cancel")}
                    </Button>
                    <Button
                        onClick={submit}
                        loading={processing}
                        disabled={supplierId === null}
                    >
                        {t("sourcing.submitRequest")}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4 px-4 py-4 text-sm sm:px-6">
                <div>
                    <p className="font-medium text-ink">{sourcing.article}</p>
                    <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted">
                        <div>
                            {t("sourcing.requested")}:{" "}
                            <span className="tabular-nums text-ink">
                                {formatQuantity(sourcing.requested_quantity)}
                            </span>
                        </div>
                        {sourcing.internal_available !== null && (
                            <div>
                                {t("sourcing.stock")}:{" "}
                                <span className="tabular-nums text-ink">
                                    {formatQuantity(sourcing.internal_available)}
                                </span>
                            </div>
                        )}
                        <div>
                            {t("sourcing.missing")}:{" "}
                            <span className="font-medium tabular-nums text-warning">
                                {formatQuantity(sourcing.shortage)}
                            </span>
                        </div>
                    </dl>
                </div>

                <div>
                    <label className="block text-xs font-medium text-ink-muted">
                        {t("sourcing.supplier")}
                    </label>
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("sourcing.searchSupplier")}
                        className="mt-1 h-9 w-full rounded-field border border-line-strong px-2"
                    />
                    <div className="mt-2 max-h-48 overflow-y-auto rounded-field border border-line">
                        {options.length === 0 && (
                            <p className="px-3 py-2 text-xs text-ink-muted">
                                {loading
                                    ? t("common.loading")
                                    : t("sourcing.noSupplier")}
                            </p>
                        )}
                        {options.map((o) => (
                            <label
                                key={o.id}
                                className="flex cursor-pointer items-center gap-2 border-b border-line px-3 py-2 last:border-b-0 hover:bg-raised"
                            >
                                <input
                                    type="radio"
                                    name="supplier"
                                    checked={supplierId === o.id}
                                    onChange={() => setSupplierId(o.id)}
                                />
                                <span className="text-ink">{o.name}</span>
                                {o.known && (
                                    <span className="rounded bg-success-soft px-1.5 text-[10px] text-success">
                                        {t("sourcing.knownSupplier")}
                                    </span>
                                )}
                                {o.phone && (
                                    <span className="ms-auto text-xs text-ink-faint">
                                        {o.phone}
                                    </span>
                                )}
                            </label>
                        ))}
                    </div>
                    {errors.supplier_id && (
                        <p className="mt-1 text-xs text-danger">
                            {errors.supplier_id}
                        </p>
                    )}
                </div>

                <label className="block text-xs font-medium text-ink-muted">
                    {t("sourcing.quantityToRequest")}
                    <input
                        inputMode="decimal"
                        value={quantity}
                        onChange={(e) => setQuantity(e.target.value)}
                        className="mt-1 h-9 w-32 rounded-field border border-line-strong px-2 text-right text-sm tabular-nums text-ink"
                    />
                    {errors.requested_quantity && (
                        <span className="mt-1 block text-danger">
                            {errors.requested_quantity}
                        </span>
                    )}
                </label>

                <label className="block text-xs font-medium text-ink-muted">
                    {t("sourcing.internalNote")}
                    <textarea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        rows={2}
                        maxLength={2000}
                        className="mt-1 w-full rounded-field border border-line-strong px-2 py-1 text-sm text-ink"
                    />
                </label>
                {(errors.line || errors.quotation) && (
                    <p className="text-xs text-danger">
                        {errors.line ?? errors.quotation}
                    </p>
                )}
            </div>
        </Dialog>
    );
}

export function RecordResultDialog({
    quotationId,
    request,
    onClose,
    only = RELOAD,
}: {
    quotationId: number;
    request: SourcingRequest;
    onClose: () => void;
    /** Inertia partial-reload keys after saving (the inbox reloads its list). */
    only?: string[];
}) {
    const { t } = useI18n();
    const [outcome, setOutcome] = useState<"available" | "unavailable">(
        request.status === "unavailable" ? "unavailable" : "available",
    );
    const [confirmed, setConfirmed] = useState(
        plainNumber(request.confirmed_quantity ?? request.requested_quantity),
    );
    const [note, setNote] = useState(request.note ?? "");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.patch(
            `/quotations/${quotationId}/supplier-requests/${request.id}`,
            {
                outcome,
                confirmed_quantity:
                    outcome === "available" ? confirmed.trim() || null : null,
                note: note.trim() || null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                only,
                onError: (e) => setErrors(e),
                onSuccess: () => onClose(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open
            onClose={onClose}
            title={t("sourcing.recordResult")}
            description={`${request.supplier_name ?? t("sourcing.supplierUnspecified")} · ${t("sourcing.requested")}: ${formatQuantity(request.requested_quantity)}`}
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>
                        {t("common.cancel")}
                    </Button>
                    <Button onClick={submit} loading={processing}>
                        {t("sourcing.saveResult")}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4 px-4 py-4 text-sm sm:px-6">
                <div className="flex gap-4">
                    {(["available", "unavailable"] as const).map((o) => (
                        <label key={o} className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="outcome"
                                checked={outcome === o}
                                onChange={() => setOutcome(o)}
                            />
                            {o === "available"
                                ? t("sourcing.available")
                                : t("sourcing.unavailable")}
                        </label>
                    ))}
                </div>
                {outcome === "available" && (
                    <label className="block text-xs font-medium text-ink-muted">
                        {t("sourcing.confirmedQuantity")}
                        <input
                            inputMode="decimal"
                            value={confirmed}
                            onChange={(e) => setConfirmed(e.target.value)}
                            className="mt-1 h-9 w-32 rounded-field border border-line-strong px-2 text-right text-sm tabular-nums text-ink"
                        />
                        <span className="mt-1 block font-normal text-ink-faint">
                            {t("sourcing.partialHint")}
                        </span>
                        {errors.confirmed_quantity && (
                            <span className="mt-1 block text-danger">
                                {errors.confirmed_quantity}
                            </span>
                        )}
                    </label>
                )}
                <label className="block text-xs font-medium text-ink-muted">
                    {t("sourcing.internalNote")}
                    <textarea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        rows={2}
                        maxLength={2000}
                        className="mt-1 w-full rounded-field border border-line-strong px-2 py-1 text-sm text-ink"
                    />
                </label>
                {(errors.status || errors.quotation) && (
                    <p className="text-xs text-danger">
                        {errors.status ?? errors.quotation}
                    </p>
                )}
            </div>
        </Dialog>
    );
}

/** Soft gate shown when "Émettre" is clicked while sourcing is unresolved. */
export function IssueSourcingDialog({
    open,
    onClose,
    lines,
    canConfirm,
    processing,
    error,
    onConfirm,
}: {
    open: boolean;
    onClose: () => void;
    lines: LineSourcing[];
    canConfirm: boolean;
    processing: boolean;
    error?: string;
    onConfirm: () => void;
}) {
    const { t } = useI18n();
    const [attested, setAttested] = useState(false);

    useEffect(() => {
        if (!open) setAttested(false);
    }, [open]);

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={t("sourcing.issueTitle")}
            description={t("sourcing.issueDescription")}
            footer={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    {canConfirm ? (
                        <label className="flex items-start gap-2 text-xs text-ink-muted">
                            <input
                                type="checkbox"
                                checked={attested}
                                onChange={(e) => setAttested(e.target.checked)}
                                className="mt-0.5"
                            />
                            {t("sourcing.confirmHelper")}
                        </label>
                    ) : (
                        <p className="text-xs text-ink-muted">
                            {t("sourcing.issueNoPermission")}
                        </p>
                    )}
                    <div className="flex shrink-0 justify-end gap-2">
                        <Button variant="secondary" onClick={onClose}>
                            {t("sourcing.backToQuotation")}
                        </Button>
                        {canConfirm && (
                            <Button
                                onClick={onConfirm}
                                loading={processing}
                                disabled={!attested}
                            >
                                {t("sourcing.confirmAndIssue")}
                            </Button>
                        )}
                    </div>
                </div>
            }
        >
            <div className="overflow-x-auto px-4 py-4 sm:px-6">
                <table className="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr className="border-b border-line text-left text-xs uppercase tracking-wide text-ink-muted [&>th]:px-2 [&>th]:py-1.5 [&>th]:font-medium">
                            <th>{t("sourcing.article")}</th>
                            <th className="text-end">
                                {t("sourcing.requested")}
                            </th>
                            <th className="text-end">{t("sourcing.stock")}</th>
                            <th className="text-end">
                                {t("sourcing.missing")}
                            </th>
                            <th>{t("sourcing.supplier")}</th>
                            <th>{t("sourcing.status")}</th>
                        </tr>
                    </thead>
                    <tbody className="[&>tr>td]:border-b [&>tr>td]:border-line [&>tr>td]:px-2 [&>tr>td]:py-2 [&>tr>td]:align-top">
                        {lines.map((l) => (
                            <tr key={l.line_id}>
                                <td className="text-ink">
                                    {l.article}
                                    {l.reference && (
                                        <span className="block text-xs text-ink-faint">
                                            {l.reference}
                                        </span>
                                    )}
                                </td>
                                <td className="text-end tabular-nums">
                                    {formatQuantity(l.requested_quantity)}
                                </td>
                                <td className="text-end tabular-nums">
                                    {l.internal_available === null
                                        ? "—"
                                        : formatQuantity(l.internal_available)}
                                </td>
                                <td className="text-end font-medium tabular-nums text-warning">
                                    {formatQuantity(l.shortage)}
                                </td>
                                <td className="text-xs text-ink-muted">
                                    {l.requests.length === 0
                                        ? "—"
                                        : l.requests
                                              .map(
                                                  (r) =>
                                                      r.supplier_name ??
                                                      t(
                                                          "sourcing.supplierUnspecified",
                                                      ),
                                              )
                                              .join(", ")}
                                </td>
                                <td>
                                    <SourcingBadge state={l.state} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {error && <p className="mt-3 text-xs text-danger">{error}</p>}
            </div>
        </Dialog>
    );
}
