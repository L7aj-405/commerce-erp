import { InstallmentEditor, InvoiceTotalsPanel, TestModeRestrictionNotice } from '@/components/finance/supplier-invoices';
import type { FormPurchaseOrder, InstallmentDraft, SupplierInvoiceFormProps } from '@/components/finance/supplier-invoices';
import { calculateLine, calculateTotals, formatScaled, interpolate, parseScaled } from '@/components/finance/supplier-invoices/math';
import { VariantPicker } from '@/components/finance/supplier-invoices/VariantPicker';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import { DateField, SelectField, TextAreaField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useRef } from 'react';
import type { FormEvent } from 'react';

const BASE = '/finance/factures-fournisseurs';

type LineDraft = {
    key: string;
    cost_nature: 'inventory' | 'expense';
    product_variant_id: number | null;
    variant_label: string | null;
    purchase_order_line_id: number | null;
    description: string;
    quantity: string;
    unit_price_ht: string;
    tax_rate_id: string;
};

type FormState = {
    store_id: string;
    supplier_id: string;
    purchase_order_id: string;
    invoice_number: string;
    invoice_date: string;
    currency_code: string;
    notes: string;
    rounding_adjustment: string;
    control_total_ttc: string;
    lines: LineDraft[];
    installments: InstallmentDraft[];
    client_operation_id: string;
    row_version: number | null;
};

let keyCounter = 0;
const nextKey = (prefix: string) => `${prefix}-${Date.now()}-${keyCounter++}`;
const newUuid = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : '00000000-0000-4000-8000-000000000000'.replace(/[08]/g, (c) => ((Number(c) ^ (Math.random() * 16)) & (15 >> (Number(c) / 4))).toString(16)));
/** "20.0000" -> "20" for display in inputs. */
const trimDecimal = (value: string) => (value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value);

export default function SupplierInvoiceForm(props: Partial<SupplierInvoiceFormProps>) {
    const { t } = useI18n();
    const page = usePage<{ sandbox?: { active: boolean } }>();
    const { invoice = null, suppliers = [], stores = [], taxRates = [], purchaseOrders = [], settings } = props;
    const editing = invoice !== null;
    const today = useMemo(() => new Date().toISOString().slice(0, 10), []);
    const operationId = useRef(newUuid());

    const form = useForm<FormState>(() => ({
        store_id: invoice ? String(invoice.store.id) : stores.length === 1 ? String(stores[0].id) : '',
        supplier_id: invoice ? String(invoice.supplier.id) : '',
        purchase_order_id: invoice?.purchase_order ? String(invoice.purchase_order.id) : '',
        invoice_number: invoice?.invoice_number ?? '',
        invoice_date: invoice?.invoice_date ?? today,
        currency_code: invoice?.currency_code ?? 'MAD',
        notes: invoice?.notes ?? '',
        rounding_adjustment: invoice ? trimDecimal(invoice.rounding_adjustment) : '',
        control_total_ttc: invoice?.control_total_ttc ? trimDecimal(invoice.control_total_ttc) : '',
        lines: (invoice?.lines ?? []).map((line) => ({
            key: `line-${line.id}`,
            cost_nature: line.cost_nature,
            product_variant_id: line.product_variant?.id ?? null,
            variant_label: line.product_variant ? [line.product_variant.product_name ?? line.product_variant.label, line.product_variant.sku].filter(Boolean).join(' · ') : null,
            purchase_order_line_id: line.purchase_order_line_id,
            description: line.description,
            quantity: trimDecimal(line.quantity),
            unit_price_ht: trimDecimal(line.unit_price_ht),
            tax_rate_id: line.tax_rate_id === null ? '' : String(line.tax_rate_id),
        })),
        installments: (invoice?.installments ?? []).map((row) => ({
            key: `inst-${row.id}`,
            due_date: row.due_date,
            amount: trimDecimal(row.amount),
            planned_payment_method: row.planned_payment_method,
            preferred_financial_account_id: row.preferred_financial_account?.id ?? null,
        })),
        client_operation_id: operationId.current,
        row_version: invoice?.row_version ?? props.row_version ?? null,
    }));
    const { data, errors } = form;
    const err = (key: string) => (errors as Record<string, string | undefined>)[key];

    const rateOf = (id: string) => taxRates.find((rate) => String(rate.id) === id)?.rate ?? '';
    const calculated = data.lines.map((line) => calculateLine(line.quantity, line.unit_price_ht, rateOf(line.tax_rate_id)));
    const totals = calculateTotals(calculated, data.rounding_adjustment);
    const hasLineProblem = totals.incomplete;

    const availablePos = purchaseOrders.filter((po) => String(po.supplier_id) === data.supplier_id && String(po.store_id) === data.store_id);
    const selectedPo: FormPurchaseOrder | undefined = purchaseOrders.find((po) => String(po.id) === data.purchase_order_id);

    const setLine = (index: number, patch: Partial<LineDraft>) => form.setData('lines', data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const addLine = () =>
        form.setData('lines', [
            ...data.lines,
            { key: nextKey('line'), cost_nature: selectedPo ? 'inventory' : 'expense', product_variant_id: null, variant_label: null, purchase_order_line_id: null, description: '', quantity: '1', unit_price_ht: '', tax_rate_id: taxRates.length === 1 ? String(taxRates[0].id) : '' },
        ]);
    const removeLine = (index: number) => form.setData('lines', data.lines.filter((_, i) => i !== index));

    const onSupplier = (value: string) => {
        const chosen = suppliers.find((s) => String(s.id) === value);
        form.setData((current) => ({
            ...current,
            supplier_id: value,
            purchase_order_id: '',
            currency_code: !editing && chosen?.default_currency_code ? chosen.default_currency_code : current.currency_code,
            lines: current.lines.map((line) => ({ ...line, purchase_order_line_id: null })),
        }));
    };
    const onStore = (value: string) => form.setData((current) => ({ ...current, store_id: value, purchase_order_id: '', lines: current.lines.map((line) => ({ ...line, purchase_order_line_id: null })) }));
    const onPo = (value: string) => {
        const po = purchaseOrders.find((p) => String(p.id) === value);
        form.setData((current) => ({
            ...current,
            purchase_order_id: value,
            currency_code: po ? po.currency_code : current.currency_code,
            lines: current.lines.map((line) => (po?.lines.some((l) => l.id === line.purchase_order_line_id) ? line : { ...line, purchase_order_line_id: null })),
        }));
    };
    const onPoLine = (index: number, value: string) => {
        const poLine = selectedPo?.lines.find((l) => String(l.id) === value);
        if (!poLine) return setLine(index, { purchase_order_line_id: null });
        const line = data.lines[index];
        setLine(index, {
            purchase_order_line_id: poLine.id,
            product_variant_id: poLine.product_variant_id,
            cost_nature: 'inventory',
            quantity: line.quantity.trim() === '' || line.quantity === '1' ? trimDecimal(poLine.quantity) : line.quantity,
            unit_price_ht: line.unit_price_ht.trim() === '' && poLine.unit_price ? trimDecimal(poLine.unit_price) : line.unit_price_ht,
        });
    };
    const onNature = (index: number, value: 'inventory' | 'expense') =>
        setLine(index, value === 'expense' ? { cost_nature: 'expense', product_variant_id: null, variant_label: null, purchase_order_line_id: null } : { cost_nature: 'inventory' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((state) => ({
            store_id: Number(state.store_id),
            supplier_id: Number(state.supplier_id),
            purchase_order_id: state.purchase_order_id === '' ? null : Number(state.purchase_order_id),
            invoice_number: state.invoice_number.trim(),
            invoice_date: state.invoice_date,
            currency_code: state.currency_code.trim().toUpperCase(),
            notes: state.notes.trim() === '' ? null : state.notes,
            rounding_adjustment: state.rounding_adjustment.trim() === '' ? '0' : state.rounding_adjustment.trim().replace(',', '.'),
            control_total_ttc: state.control_total_ttc.trim() === '' ? null : state.control_total_ttc.trim().replace(',', '.'),
            lines: state.lines.map((line) => ({
                cost_nature: line.cost_nature,
                product_variant_id: line.product_variant_id,
                purchase_order_line_id: line.purchase_order_line_id,
                description: line.description.trim(),
                quantity: line.quantity.trim().replace(',', '.'),
                unit_price_ht: line.unit_price_ht.trim().replace(',', '.'),
                tax_rate_id: line.tax_rate_id === '' ? null : Number(line.tax_rate_id),
            })),
            installments: state.installments.map((row) => ({
                due_date: row.due_date,
                amount: row.amount.trim().replace(',', '.'),
                planned_payment_method: row.planned_payment_method ?? null,
                preferred_financial_account_id: row.preferred_financial_account_id ?? null,
            })),
            ...(editing ? { row_version: state.row_version } : { client_operation_id: state.client_operation_id }),
        }));
        if (editing && invoice) form.put(`${BASE}/${invoice.id}`, { preserveScroll: true });
        else form.post(BASE, { preserveScroll: true });
    };

    // installment errors keyed "installments.N.field" -> per-row map for the editor
    const installmentErrors: Record<number, { due_date?: string; amount?: string }> = {};
    Object.entries(errors as Record<string, string>).forEach(([key, message]) => {
        const match = /^installments\.(\d+)\.(due_date|amount)$/.exec(key);
        if (match) installmentErrors[Number(match[1])] = { ...installmentErrors[Number(match[1])], [match[2]]: message };
    });

    const title = editing ? t('supplierInvoice.form.titleEdit') : t('supplierInvoice.form.titleCreate');
    const back = (
        <ButtonLink href={editing && invoice ? `${BASE}/${invoice.id}` : BASE} variant="secondary">
            {t('common.cancel')}
        </ButtonLink>
    );

    if (page.props.sandbox?.active) {
        return (
            <ApplicationShell wide>
                <Head title={title} />
                <PageHeader title={title} actions={back} />
                <TestModeRestrictionNotice />
            </ApplicationShell>
        );
    }
    if (editing && invoice && invoice.status !== 'draft') {
        return (
            <ApplicationShell wide>
                <Head title={title} />
                <PageHeader title={title} actions={back} />
                <Alert tone="warning">{t('supplierInvoice.form.notDraft')}</Alert>
            </ApplicationShell>
        );
    }
    if (!editing && stores.length === 0) {
        return (
            <ApplicationShell wide>
                <Head title={title} />
                <PageHeader title={title} actions={back} />
                <EmptyState title={t('supplierInvoice.form.noStores')} />
            </ApplicationShell>
        );
    }

    const errorKeys = Object.keys(errors);
    const fieldCard = 'surface-card p-5';
    const preferredCurrency = data.currency_code || 'MAD';

    return (
        <ApplicationShell wide>
            <Head title={title} />
            <PageHeader title={title} description={invoice ? `${invoice.internal_number} · ${invoice.supplier.name}` : t('supplierInvoice.form.description')} actions={back} />

            <form onSubmit={submit} noValidate className="space-y-6">
                {errorKeys.length > 0 && (
                    <Alert tone="danger" title={t('supplierInvoice.form.errorsSummary')} />
                )}
                {err('row_version') && (
                    <Alert tone="warning" title={t('supplierInvoice.form.staleTitle')} actions={editing && invoice ? <Button type="button" variant="secondary" size="sm" onClick={() => router.visit(`${BASE}/${invoice.id}/modifier`)}>{t('supplierInvoice.form.staleReload')}</Button> : undefined}>
                        {err('row_version')}
                    </Alert>
                )}
                {err('invoice') && <Alert tone="danger">{err('invoice')}</Alert>}
                {taxRates.length === 0 && <Alert tone="warning">{t('supplierInvoice.form.noTaxRates')}</Alert>}

                <section aria-labelledby="si-form-header" className={fieldCard}>
                    <h2 id="si-form-header" className="mb-4 text-base font-semibold">{t('supplierInvoice.form.sectionHeader')}</h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <SelectField label={t('supplierInvoice.form.store')} required value={data.store_id} onChange={(e) => onStore(e.target.value)} disabled={editing} error={err('store_id')}>
                            <option value="">{t('supplierInvoice.form.choose')}</option>
                            {stores.map((store) => (
                                <option key={store.id} value={store.id}>{store.name}</option>
                            ))}
                        </SelectField>
                        <SelectField label={t('supplierInvoice.form.supplier')} required value={data.supplier_id} onChange={(e) => onSupplier(e.target.value)} error={err('supplier_id')}>
                            <option value="">{t('supplierInvoice.form.choose')}</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </SelectField>
                        <SelectField label={t('supplierInvoice.form.purchaseOrder')} value={data.purchase_order_id} onChange={(e) => onPo(e.target.value)} error={err('purchase_order_id')}>
                            <option value="">{t('supplierInvoice.form.noPurchaseOrder')}</option>
                            {availablePos.map((po) => (
                                <option key={po.id} value={po.id}>{po.number}</option>
                            ))}
                        </SelectField>
                        <TextField label={t('supplierInvoice.form.invoiceNumber')} required dir="ltr" maxLength={64} value={data.invoice_number} onChange={(e) => form.setData('invoice_number', e.target.value)} error={err('invoice_number')} />
                        <DateField label={t('supplierInvoice.form.invoiceDate')} required value={data.invoice_date} onChange={(value: string) => form.setData('invoice_date', value)} error={err('invoice_date')} />
                        <TextField label={t('supplierInvoice.form.currency')} required dir="ltr" maxLength={3} value={data.currency_code} onChange={(e) => form.setData('currency_code', e.target.value.toUpperCase())} error={err('currency_code')} />
                    </div>
                    <TextAreaField className="mt-4" label={t('supplierInvoice.form.notes')} value={data.notes} onChange={(e) => form.setData('notes', e.target.value)} error={err('notes')} />
                </section>

                <section aria-labelledby="si-form-lines" className={fieldCard}>
                    <h2 id="si-form-lines" className="mb-4 text-base font-semibold">{t('supplierInvoice.form.lines')}</h2>
                    {err('lines') && <p className="mb-3 text-[13px] font-medium text-danger">{err('lines')}</p>}
                    <ul className="space-y-4">
                        {data.lines.map((line, index) => {
                            const calc = calculated[index];
                            const f = (field: string) => err(`lines.${index}.${field}`);
                            const poLines = selectedPo?.lines ?? [];
                            return (
                                <li key={line.key} className="rounded-card border border-line p-4">
                                    <div className="mb-3 flex items-center justify-between gap-2">
                                        <h3 className="text-sm font-semibold text-ink">{interpolate(t('supplierInvoice.form.line'), { n: index + 1 })}</h3>
                                        <Button type="button" variant="ghost" size="sm" onClick={() => removeLine(index)} aria-label={interpolate(t('supplierInvoice.form.removeLine'), { n: index + 1 })}>
                                            {t('common.remove')}
                                        </Button>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <SelectField label={t('supplierInvoice.form.nature')} value={line.cost_nature} onChange={(e) => onNature(index, e.target.value as 'inventory' | 'expense')} error={f('cost_nature')} hint={line.cost_nature === 'expense' ? t('supplierInvoice.form.natureNeedsPo') : undefined}>
                                            <option value="expense">{t('supplierInvoice.form.nature.expense')}</option>
                                            <option value="inventory">{t('supplierInvoice.form.nature.inventory')}</option>
                                        </SelectField>
                                        {selectedPo && line.cost_nature === 'inventory' ? (
                                            <SelectField label={t('supplierInvoice.form.poLine')} value={line.purchase_order_line_id === null ? '' : String(line.purchase_order_line_id)} onChange={(e) => onPoLine(index, e.target.value)} error={f('purchase_order_line_id') ?? f('product_variant_id')}>
                                                <option value="">{t('supplierInvoice.form.noPoLine')}</option>
                                                {poLines.map((l) => (
                                                    <option key={l.id} value={l.id}>{interpolate(t('supplierInvoice.form.poLineLabel'), { id: l.id, qty: trimDecimal(l.quantity) })}</option>
                                                ))}
                                            </SelectField>
                                        ) : (
                                            <div />
                                        )}
                                        {line.cost_nature === 'inventory' && line.purchase_order_line_id === null && (
                                            <VariantPicker
                                                searchUrl={`${BASE}/variant-search`}
                                                value={line.product_variant_id}
                                                selectedLabel={line.variant_label}
                                                onSelect={(variant) => setLine(index, { product_variant_id: variant?.id ?? null, variant_label: variant?.label ?? null })}
                                                error={f('product_variant_id')}
                                            />
                                        )}
                                        <TextField className="sm:col-span-2" label={t('supplierInvoice.form.description2')} required maxLength={255} value={line.description} onChange={(e) => setLine(index, { description: e.target.value })} error={f('description')} />
                                        <TextField label={t('supplierInvoice.form.quantity')} required inputMode="decimal" dir="ltr" value={line.quantity} onChange={(e) => setLine(index, { quantity: e.target.value })} error={f('quantity') ?? (line.quantity.trim() !== '' && parseScaled(line.quantity) === null ? '!' : undefined)} />
                                        <TextField label={t('supplierInvoice.form.unitPriceHt')} required inputMode="decimal" dir="ltr" value={line.unit_price_ht} onChange={(e) => setLine(index, { unit_price_ht: e.target.value })} error={f('unit_price_ht')} />
                                        <SelectField label={t('supplierInvoice.form.taxRate')} required value={line.tax_rate_id} onChange={(e) => setLine(index, { tax_rate_id: e.target.value })} error={f('tax_rate_id')}>
                                            <option value="">{t('supplierInvoice.form.choose')}</option>
                                            {taxRates.map((rate) => (
                                                <option key={rate.id} value={rate.id}>{`${rate.name} (${trimDecimal(rate.rate)} %)`}</option>
                                            ))}
                                        </SelectField>
                                        <div>
                                            <p className="mb-1.5 text-sm font-medium text-ink">{t('supplierInvoice.form.lineTotal')}</p>
                                            <p className="flex min-h-11 items-center font-semibold tabular-nums text-ink">{calc ? formatMoney(calc.line_ttc, preferredCurrency) : '—'}</p>
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                    <Button type="button" variant="secondary" size="sm" className="mt-4" onClick={addLine}>
                        {t('supplierInvoice.form.addLine')}
                    </Button>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="space-y-4">
                        <div className={fieldCard}>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField label={t('supplierInvoice.form.roundingAdjustment')} inputMode="decimal" dir="ltr" placeholder="0" value={data.rounding_adjustment} onChange={(e) => form.setData('rounding_adjustment', e.target.value)} error={err('rounding_adjustment')} hint={settings ? `± ${formatMoney(settings.rounding_tolerance, preferredCurrency)}` : undefined} />
                                <TextField label={t('supplierInvoice.form.controlTotal')} inputMode="decimal" dir="ltr" value={data.control_total_ttc} onChange={(e) => form.setData('control_total_ttc', e.target.value)} error={err('control_total_ttc')} hint={t('supplierInvoice.form.controlTotalHint')} />
                            </div>
                        </div>
                        <InvoiceTotalsPanel subtotalHt={totals.subtotal_ht} totalTva={totals.total_tva} roundingAdjustment={totals.rounding_adjustment} totalTtc={totals.total_ttc} controlTotalTtc={data.control_total_ttc.trim() === '' ? null : formatScaled(parseScaled(data.control_total_ttc) ?? 0n)} currencyCode={preferredCurrency} />
                        <p className="text-[12.5px] text-ink-faint">{t('supplierInvoice.form.previewNote')}</p>
                    </div>
                    <div>
                        <InstallmentEditor rows={data.installments} onChange={(rows) => form.setData('installments', rows)} totalTtc={totals.total_ttc} currencyCode={preferredCurrency} invoiceDate={data.invoice_date} errors={installmentErrors} />
                        {err('installments') && <p className="mt-2 text-[13px] font-medium text-danger">{err('installments')}</p>}
                    </div>
                </div>

                <div className="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    {back}
                    <Button type="submit" loading={form.processing} loadingText={t('supplierInvoice.form.saving')} disabled={taxRates.length === 0 || (hasLineProblem && data.lines.length === 0)}>
                        {t('supplierInvoice.form.save')}
                    </Button>
                </div>
            </form>
        </ApplicationShell>
    );
}
