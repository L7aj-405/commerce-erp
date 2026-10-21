import { useI18n } from "@/i18n";

export default function StepIndicator({ current }: { current: number }) {
    const { t } = useI18n();
    const steps = [
        "file",
        "columns",
        "verification",
        "import",
        "result",
    ] as const;
    return (
        <ol
            className="mb-8 grid grid-cols-5 gap-2"
            aria-label={t("imports.steps.label")}
        >
            {steps.map((step, index) => {
                const number = index + 1;
                const active = number === current;
                const done = number < current;
                return (
                    <li key={step} className="min-w-0">
                        <div
                            className={`h-1 rounded-full ${done || active ? "bg-slate-950" : "bg-slate-200"}`}
                        />
                        <div
                            className={`mt-2 truncate text-xs ${active ? "font-semibold text-slate-950" : "text-slate-500"}`}
                        >
                            {number}.{" "}
                            {t(
                                `imports.steps.${step}` as Parameters<
                                    typeof t
                                >[0],
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
