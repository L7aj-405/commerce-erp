import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import SoldLines, { type SoldLine } from '@/components/finance/SoldLines';
import PageHeader from '@/components/ui/PageHeader';
import EmptyState from '@/components/ui/EmptyState';
import { Dropdown } from '@/components/ui/Menu';
import { Badge } from '@/components/ui/StatusBadge';
import type { StatusVariant } from '@/components/ui/StatusBadge';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { Spinner } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/toast';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

type CaEncaisseRow = {
    id: number;
    payment_number: string;
    sale_date: string;
    payment_date: string;
    reference: string;
    reference_type: 'invoice' | 'order';
    invoice_id: number | null;
    nature_label: 'Avance' | 'Règlement facture';
    sales_order_id: number;
    order_number: string;
    lines: SoldLine[];
    customer: string;
    method_label: string;
    amount: string;
    status_label: string;
};
type PaymentLine = Pick<CaEncaisseRow, 'id' | 'payment_number' | 'payment_date' | 'method_label' | 'amount'>;
type CaEncaisseGroup = Omit<CaEncaisseRow, 'id' | 'payment_number' | 'payment_date' | 'method_label' | 'amount' | 'status_label'> & {
    id: string;
    amount: string;
    status_label: string;
    payments: PaymentLine[];
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    total: string;
    rows: { data: CaEncaisseRow[]; links: LinkData[]; total: number; from?: number | null; to?: number | null };
    can: { export: boolean };
};

const STATUS_VARIANTS: Record<string, StatusVariant> = {
    'Paiement comptant': 'success',
    'Paiement complété': 'success',
    'Paiement partiel': 'warning',
    'Solde / Reliquat': 'warning',
};

const completedStatuses = new Set(['Paiement comptant', 'Solde / Reliquat']);

function toTenThousandths(value: string): bigint {
    const clean = value.trim();
    const sign = clean.startsWith('-') ? -1n : 1n;
    const [whole, fraction = ''] = clean.replace(/^-/, '').split('.');

    return sign * (BigInt(whole || '0') * 10000n + BigInt(fraction.padEnd(4, '0').slice(0, 4)));
}

function fromTenThousandths(value: bigint): string {
    const sign = value < 0n ? '-' : '';
    const absolute = value < 0n ? -value : value;
    const whole = absolute / 10000n;
    const fraction = String(absolute % 10000n).padStart(4, '0');

    return `${sign}${whole}.${fraction}`;
}

function groupRows(rows: CaEncaisseRow[]): CaEncaisseGroup[] {
    const groups = new Map<string, CaEncaisseGroup>();

    rows.forEach((row) => {
        const key = row.reference_type === 'invoice' && row.invoice_id ? `invoice:${row.invoice_id}` : `order:${row.sales_order_id}`;
        const existing = groups.get(key);
        const payment = {
            id: row.id,
            payment_number: row.payment_number,
            payment_date: row.payment_date,
            method_label: row.method_label,
            amount: row.amount,
        };

        if (!existing) {
            groups.set(key, {
                id: key,
                sale_date: row.sale_date,
                reference: row.reference,
                reference_type: row.reference_type,
                invoice_id: row.invoice_id,
                nature_label: row.nature_label,
                sales_order_id: row.sales_order_id,
                order_number: row.order_number,
                lines: row.lines,
                customer: row.customer,
                amount: row.amount,
                status_label: row.status_label,
                payments: [payment],
            });
            return;
        }

        existing.amount = fromTenThousandths(toTenThousandths(existing.amount) + toTenThousandths(row.amount));
        existing.payments.push(payment);
        if (completedStatuses.has(row.status_label)) {
            existing.status_label = 'Paiement complété';
        }
    });

    return Array.from(groups.values()).map((group) => ({
        ...group,
        status_label: completedStatuses.has(group.status_label) ? 'Paiement complété' : group.status_label,
    }));
}

export default function FinanceCaEncaisse({ organization, period, periodLabel, storeId, stores, total, rows, can }: Props) {
    const { t } = useI18n();
    const q = new URLSearchParams({ month: period, store_id: storeId ? String(storeId) : '' }).toString();
    const groups = groupRows(rows.data);
    const statusLabels: Record<string, string> = {
        'Paiement comptant': t('finance.cash.cashPayment'),
        'Paiement complété': t('finance.cash.completedPayment'),
        'Paiement partiel': t('finance.cash.partialPayment'),
        'Solde / Reliquat': t('finance.cash.balance'),
    };
    const natureLabels: Record<string, string> = { Avance: t('finance.cash.advance'), 'Règlement facture': t('finance.cash.invoiceSettlement') };

    return (
        <ApplicationShell wide>
            <Head title={`${t('finance.cash.title')} — Finance`} />
            <PageHeader
                title={t('finance.cash.title')}
                description={`${organization.name} · ${periodLabel} · ${t('finance.cash.description')}`}
                actions={
                    can.export ? (
                        <ExportMenu
                            xlsxHref={`/finance/ca-encaisse/export/xlsx?${q}`}
                            pdfHrefBase={`/finance/ca-encaisse/export/pdf?${q}`}
                            zipHref={`/finance/ca-encaisse/export/invoices-zip?${q}`}
                            packageHref={`/finance/ca-encaisse/export/full-package?${q}`}
                            zipFilename={`Factures_${period}.zip`}
                            packageFilename={`Package_CA_${period}.zip`}
                        />
                    ) : undefined
                }
            />
            <FinanceFilters path="/finance/ca-encaisse" period={period} storeId={storeId} stores={stores} />

            <section className="surface-card mb-4 p-5" aria-label={t('finance.cash.monthTotal')}>
                <p className="text-[13px] font-medium text-ink-muted">{t('finance.cash.monthTotal')}</p>
                <p className="metric-value mt-1.5 text-2xl font-semibold text-ink" dir="ltr">
                    <bdi>{formatMoney(total)}</bdi>
                </p>
            </section>

            {/* Desktop/tablet: table */}
            <TableShell
                label={t('finance.cash.title')}
                state={rows.data.length === 0 ? 'empty' : 'ready'}
                emptyTitle={t('finance.cash.empty')}
                className="hidden md:block"
                footer={rows.data.length === 0 ? undefined : <TablePager links={rows.links} from={rows.from} to={rows.to} total={rows.total} />}
            >
                <table className="erp-table min-w-[1180px] text-sm">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('finance.cash.paymentNumber')}</th>
                                    <th className="px-4 py-2.5">{t('finance.cash.saleDate')}</th>
                                    <th className="px-4 py-2.5">{t('finance.cash.paymentDate')}</th>
                                    <th className="px-4 py-2.5">{t('finance.cash.invoiceOrder')}</th>
                                    <th className="px-4 py-2.5">{t('finance.cash.nature')}</th>
                                    <th className="px-4 py-2.5">{t('finance.cash.designation')}</th>
                                    <th className="px-4 py-2.5">{t('common.customer')}</th>
                                    <th className="px-4 py-2.5">{t('finance.cash.collectionMethod')}</th>
                                    <th className="num px-4 py-2.5">{t('finance.cash.collectedAmount')}</th>
                                    <th className="px-4 py-2.5">{t('finance.cash.paymentStatus')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.map((group) => (
                                    <tr key={group.id} className="align-top">
                                        <td className="px-4 py-2.5">
                                            <div className="divide-y divide-line">
                                                {group.payments.map((payment) => (
                                                    <div key={payment.id} className="py-1 first:pt-0 last:pb-0">
                                                        <bdi className="font-medium text-ink">{payment.payment_number}</bdi>
                                                        <p className="text-[11px] text-ink-faint">{t('finance.cash.partialAmount')} {formatMoney(payment.amount)}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2.5 whitespace-nowrap text-ink-muted">{formatDate(group.sale_date)}</td>
                                        <td className="px-4 py-2.5">
                                            <div className="divide-y divide-line">
                                                {group.payments.map((payment) => (
                                                    <div key={payment.id} className="py-1 first:pt-0 last:pb-0 whitespace-nowrap text-ink-muted">{formatDate(payment.payment_date)}</div>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {group.reference_type === 'invoice' && group.invoice_id ? (
                                                <Link href={`/invoices/${group.invoice_id}`}><bdi>{group.reference}</bdi></Link>
                                            ) : (
                                                <Link href={`/sales/orders/${group.sales_order_id}`}><bdi>{group.reference}</bdi></Link>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <Badge variant="neutral">{natureLabels[group.nature_label] ?? group.nature_label}</Badge>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <SoldLines lines={group.lines} />
                                        </td>
                                        <td className="px-4 py-2.5">{group.customer}</td>
                                        <td className="px-4 py-2.5">
                                            <div className="divide-y divide-line">
                                                {group.payments.map((payment) => (
                                                    <div key={payment.id} className="py-1 first:pt-0 last:pb-0 text-ink-muted">{payment.method_label}</div>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="num px-4 py-2.5">
                                            <p className="font-semibold text-ink" dir="ltr">{formatMoney(group.amount)}</p>
                                            {group.payments.length > 1 && <p className="text-[11px] text-ink-faint">{t('finance.cash.collectedAmount')}</p>}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <Badge variant={STATUS_VARIANTS[group.status_label] ?? 'neutral'}>{statusLabels[group.status_label] ?? group.status_label}</Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
            </TableShell>

            {/* Mobile: stacked cards */}
            <div className="space-y-3 md:hidden">
                {rows.data.length === 0 ? (
                    <EmptyState compact title={t('finance.cash.empty')} />
                ) : (
                    <>
                        <ul className="space-y-3">
                        {groups.map((group) => (
                            <li key={group.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <p className="min-w-0 truncate text-sm font-semibold text-ink">{group.reference}</p>
                                    <Badge variant={STATUS_VARIANTS[group.status_label] ?? 'neutral'} className="shrink-0">{statusLabels[group.status_label] ?? group.status_label}</Badge>
                                </div>
                                <div className="mt-2.5">
                                    <p className="text-[12px] font-medium text-ink-faint">{t('finance.cash.designation')}</p>
                                    <div className="mt-1">
                                        <SoldLines lines={group.lines} />
                                    </div>
                                </div>
                                <div className="mt-3 divide-y divide-line rounded-field bg-raised px-3">
                                    {group.payments.map((payment) => (
                                        <div key={payment.id} className="grid grid-cols-2 gap-2 py-2 text-[13px]">
                                            <div>
                                                <bdi className="font-medium text-ink">{payment.payment_number}</bdi>
                                                <p className="text-ink-faint">{formatDate(payment.payment_date)} · {payment.method_label}</p>
                                            </div>
                                            <div className="text-end">
                                                <p className="text-ink-faint">{t('finance.cash.partialAmount')}</p>
                                                <p className="font-semibold tabular-nums text-ink" dir="ltr">{formatMoney(payment.amount)}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('common.customer')}</dt>
                                        <dd className="truncate text-ink-muted">{group.customer}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.cash.reference')}</dt>
                                        <dd className="truncate">
                                            {group.reference_type === 'invoice' && group.invoice_id ? (
                                                <Link href={`/invoices/${group.invoice_id}`}><bdi>{group.reference}</bdi></Link>
                                            ) : (
                                                <Link href={`/sales/orders/${group.sales_order_id}`}><bdi>{group.reference}</bdi></Link>
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.cash.nature')}</dt>
                                        <dd className="truncate text-ink-muted">{natureLabels[group.nature_label] ?? group.nature_label}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.cash.saleDate')}</dt>
                                        <dd className="text-ink-muted">{formatDate(group.sale_date)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('finance.cash.collectedAmount')}</dt>
                                        <dd className="tabular-nums font-semibold text-ink" dir="ltr">{formatMoney(group.amount)}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                        <TablePager links={rows.links} from={rows.from} to={rows.to} total={rows.total} />
                    </>
                )}
            </div>
        </ApplicationShell>
    );
}

/**
 * Unified "Exporter" menu — Excel, the PDF report (either orientation), and
 * the invoice ZIP — replacing what used to be two separate action buttons.
 * A single dropdown keeps the header action area from growing a third
 * button that would crowd the mobile layout (see the app-wide responsive
 * pass): plain `<a>` downloads for Excel/PDF (never Inertia's `<Link>`,
 * which would try to render the binary response as a page), and a
 * fetch-then-save flow for the ZIP so a "no invoices"/oversized-selection
 * error can be shown as a message instead of downloading a corrupt file.
 */
function ExportMenu({
    xlsxHref,
    pdfHrefBase,
    zipHref,
    packageHref,
    zipFilename,
    packageFilename,
}: {
    xlsxHref: string;
    pdfHrefBase: string;
    zipHref: string;
    packageHref: string;
    zipFilename: string;
    packageFilename: string;
}) {
    const { t } = useI18n();
    const [zipBusy, setZipBusy] = useState(false);
    const toast = useToast();
    const itemClass =
        'flex min-h-10 w-full cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-start text-sm text-ink transition-soft hover:bg-sage focus-visible:bg-sage focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-60';

    const downloadZip = async (href: string, filename: string, errorMessage: string) => {
        if (zipBusy) return;
        setZipBusy(true);
        try {
            const response = await fetch(href, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                const data = (await response.json().catch(() => ({}))) as { message?: string };
                toast.error(data.message ?? errorMessage);
                return;
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch {
            toast.error(errorMessage);
        } finally {
            setZipBusy(false);
        }
    };

    return (
        <Dropdown
            label={zipBusy ? t('finance.cash.generatingZip') : t('finance.cash.export')}
            icon={zipBusy ? <Spinner size="xs" /> : undefined}
            align="end"
            width="w-64"
            buttonClassName="inline-flex min-h-10 cursor-pointer items-center justify-center gap-1.5 rounded-field border border-line-strong bg-surface px-3.5 text-sm font-medium text-ink transition-soft hover:bg-raised"
        >
            <a href={xlsxHref} className={itemClass}>
                Excel (.xlsx)
            </a>
            <a href={`${pdfHrefBase}&orientation=landscape`} className={itemClass}>
                {t('finance.cash.pdfLandscape')}
            </a>
            <a href={`${pdfHrefBase}&orientation=portrait`} className={itemClass}>
                {t('finance.cash.pdfPortrait')}
            </a>
            <div className="my-1 border-t border-line" role="separator" />
            <button
                type="button"
                onClick={() => downloadZip(zipHref, zipFilename, t('finance.cash.invoiceExportFailed'))}
                disabled={zipBusy}
                aria-busy={zipBusy || undefined}
                className={itemClass}
            >
                {t('finance.cash.invoicesZip')}
            </button>
            <button
                type="button"
                onClick={() => downloadZip(packageHref, packageFilename, t('finance.cash.packageExportFailed'))}
                disabled={zipBusy}
                aria-busy={zipBusy || undefined}
                className={itemClass}
            >
                {t('finance.cash.fullPackage')}
            </button>
        </Dropdown>
    );
}
