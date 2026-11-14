import { Button } from "@/components/ui/Button";
import RoleDashboard from "@/components/dashboard/RoleDashboard";
import { TextField } from "@/components/ui/form";
import { useI18n } from "@/i18n";
import ApplicationShell from "@/layouts/ApplicationShell";
import type {
    SharedPageProps,
    TenantOrganization,
    TenantStore,
} from "@/types/app";
import { Head, useForm, usePage } from "@inertiajs/react";
import type { FormEvent } from "react";

export type DashboardProps = Pick<Props, "dashboard" | "onboarding" | "executiveDashboard">;

type Props = {
    organizations: TenantOrganization[];
    stores: TenantStore[];
    dashboard: {
        product_count: number | null;
        stocked_item_count: number | null;
        sales_today: number | null;
        payments_to_receive: number | null;
        woo_stock_tasks_pending: number | null;
        out_of_stock_articles_pending: number | null;
    };
    onboarding: {
        organization: boolean;
        store: boolean;
        products: boolean;
        stock: boolean;
        first_sale: boolean;
    };
    executiveDashboard: null | {
        period: { month: string; label: string };
        primary: {
            net_sales: { value: string; variation: string | null };
            net_collections: { value: string; variation: string | null };
            orders: { value: number; variation: string | null };
            receivables: { value: string; variation: null };
        };
        secondary: {
            average_basket: string;
            partial_orders: number;
            unpaid_orders: number;
            refunds: string;
            returns: { count: number; amount: string };
            out_of_stock: number;
        };
        charts: {
            sales_vs_collections: Array<{
                label: string;
                sales: string;
                collections: string;
            }>;
            order_states: {
                paid: number;
                partially_paid: number;
                unpaid: number;
            };
            top_products: Array<{
                label: string;
                value: string;
                quantity: string;
            }>;
        };
        attention: Array<{
            label: string;
            value: string | number;
            kind: "money" | "count";
            href: string;
        }>;
    };
};

export default function PlatformIndex({
    organizations,
    stores,
    dashboard,
    onboarding,
    executiveDashboard,
}: Props) {
    const { t } = useI18n();
    const page = usePage<SharedPageProps>();
    const { tenant, auth } = page.props;
    const organizationForm = useForm({ name: "" });
    const storeForm = useForm({ name: "", code: "" });
    const can = (permission: string) => tenant.permissions.includes(permission);
    const firstName =
        (auth.user?.name ?? "").trim().split(" ")[0] || t("platform.welcome");

    const needsOrganization = organizations.length === 0;
    const needsStore =
        !!tenant.organization && stores.length === 0 && can("stores.create");
    const inSetup = needsOrganization || needsStore;

    const createOrganization = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        organizationForm.post("/organizations");
    };
    const createStore = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        storeForm.post("/stores");
    };

    return (
        <ApplicationShell fluid={!inSetup}>
            <Head
                title={
                    inSetup ? t("platform.prepareSpace") : t("platform.home")
                }
            />

            {inSetup ? (
                <div className="mx-auto max-w-xl">
                    <p className="text-sm font-medium text-ink-muted">
                        {t("platform.welcome")}
                    </p>
                    <h1 className="mt-1 text-2xl font-semibold text-ink">
                        {t("platform.greetingSetup").replace(
                            "{name}",
                            firstName,
                        )}
                    </h1>
                    <p className="mt-1.5 text-sm text-ink-muted">
                        {t("platform.twoSteps")}
                    </p>

                    <ol className="mt-6 flex items-center gap-3 text-[13px]">
                        <Step
                            index={1}
                            label={t("common.organization")}
                            state={needsOrganization ? "current" : "done"}
                        />
                        <span className="h-px flex-1 bg-line" />
                        <Step
                            index={2}
                            label={t("common.store")}
                            state={
                                needsOrganization
                                    ? "todo"
                                    : needsStore
                                      ? "current"
                                      : "done"
                            }
                        />
                    </ol>

                    {needsOrganization && (
                        <section className="mt-6 rounded-card border border-line bg-surface p-6 shadow-card">
                            <h2 className="text-lg font-semibold text-ink">
                                {t("platform.createOrganization")}
                            </h2>
                            <p className="mt-1 text-sm text-ink-muted">
                                {t("platform.organizationHelp")}
                            </p>
                            <form
                                onSubmit={createOrganization}
                                className="mt-5 space-y-4"
                            >
                                <TextField
                                    label={t("platform.organizationName")}
                                    name="name"
                                    required
                                    maxLength={255}
                                    autoComplete="organization"
                                    placeholder="Ex. AV Professional"
                                    value={organizationForm.data.name}
                                    onChange={(event) =>
                                        organizationForm.setData(
                                            "name",
                                            event.target.value,
                                        )
                                    }
                                    error={organizationForm.errors.name}
                                />
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={organizationForm.processing}
                                >
                                    {organizationForm.processing
                                        ? t("common.creating")
                                        : t("platform.continue")}
                                </Button>
                            </form>
                        </section>
                    )}

                    {needsStore && (
                        <section className="mt-6 rounded-card border border-line bg-surface p-6 shadow-card">
                            <h2 className="text-lg font-semibold text-ink">
                                {t("platform.createFirstStore")}
                            </h2>
                            <p className="mt-1 text-sm text-ink-muted">
                                {t("platform.storeHelp")}
                            </p>
                            <form
                                onSubmit={createStore}
                                className="mt-5 grid gap-4 sm:grid-cols-2"
                            >
                                <TextField
                                    label={t("platform.storeName")}
                                    name="name"
                                    required
                                    maxLength={255}
                                    placeholder={t(
                                        "platform.mainStorePlaceholder",
                                    )}
                                    value={storeForm.data.name}
                                    onChange={(event) =>
                                        storeForm.setData(
                                            "name",
                                            event.target.value,
                                        )
                                    }
                                    error={storeForm.errors.name}
                                />
                                <TextField
                                    label="Code"
                                    name="code"
                                    required
                                    maxLength={64}
                                    placeholder="MAIN"
                                    value={storeForm.data.code}
                                    onChange={(event) =>
                                        storeForm.setData(
                                            "code",
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                    error={storeForm.errors.code}
                                />
                                <div className="sm:col-span-2">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        disabled={storeForm.processing}
                                    >
                                        {storeForm.processing
                                            ? t("common.creating")
                                            : t("platform.enterApplication")}
                                    </Button>
                                </div>
                            </form>
                        </section>
                    )}
                </div>
            ) : (
                <RoleDashboard
                    firstName={firstName}
                    dashboard={dashboard}
                    onboarding={onboarding}
                    executiveDashboard={executiveDashboard}
                />
            )}
        </ApplicationShell>
    );
}

function Step({
    index,
    label,
    state,
}: {
    index: number;
    label: string;
    state: "todo" | "current" | "done";
}) {
    return (
        <span className="flex items-center gap-2">
            <span
                className={`grid size-6 place-items-center rounded-full text-xs font-semibold ${
                    state === "done"
                        ? "bg-success-soft text-success"
                        : state === "current"
                          ? "bg-primary text-primary-fg"
                          : "bg-sage text-ink-faint"
                }`}
            >
                {state === "done" ? "✓" : index}
            </span>
            <span
                className={
                    state === "current"
                        ? "font-medium text-ink"
                        : "text-ink-muted"
                }
            >
                {label}
            </span>
        </span>
    );
}
