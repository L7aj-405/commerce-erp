export type Warehouse = { id: number; name: string; code: string };
export type TaxRate = { id: number; name: string; rate: string };
export type Option = { id: number; name: string };
export type Supplier = { id: number; name: string };
export type LineProcurement = {
    id: number;
    procurement_number: string;
    status: string;
    status_label: string;
    supplier_availability_status: string;
    supplier: Option | null;
    quantity: string;
};
export type Customer = {
    id: number;
    type?: 'individual' | 'business';
    display_name: string;
    company_name: string | null;
    phone: string | null;
    email: string | null;
    tax_identifier?: string | null;
    billing_address?: string | null;
};
export type ProductResult = {
    id: number;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    barcode: string | null;
    image_url: string | null;
    brand: Option | null;
    /** Effective HT (tax-exclusive) unit price — stored, or derived from TTC + tax. */
    unit_price_excl_tax: string;
    /** Public price shown to the customer in the POS (HT + tax). */
    unit_price_incl_tax: string;
    /** 'stored' | 'derived' | 'unknown' — how the HT above was obtained. */
    ht_source?: string;
    /** True when the HT/VAT split cannot be resolved (no explicit HT, no tax rate). */
    tax_config_missing?: boolean;
    default_sale_price: string;
    tax_rate: TaxRate | null;
    stock: { on_hand: string; reserved: string; available: string; total_available: string };
};
export type CartLine = {
    id: number;
    line_type: 'catalog' | 'custom';
    product_variant_id?: number;
    description: string;
    variant_name?: string | null;
    sku?: string | null;
    reference?: string | null;
    unit_label?: string | null;
    image_url?: string | null;
    brand?: Option | null;
    warehouse?: Warehouse | null;
    quantity: string;
    unit_price_excl_tax: string;
    unit_price_incl_tax?: string;
    line_subtotal?: string;
    line_discount?: string;
    line_taxable?: string;
    line_tax_amount?: string;
    line_total?: string;
    tax_rate_id?: number | null;
    tax_rate: string;
    tax_name?: string | null;
    discount_type: 'none' | 'fixed' | 'percentage';
    discount_value: string;
    available?: string;
    local_available?: string;
    total_available?: string;
    remote_required?: string;
    requires_replenishment?: boolean;
    insufficient?: boolean;
    /** Supplier special-order sourcing for this line. */
    company_covered?: string;
    to_procure?: string;
    procurement_confirmed_qty?: string;
    needs_procurement?: boolean;
    procurement?: LineProcurement | null;
};
export type ActiveSale = {
    id: number;
    order_number: string;
    customer: Customer | null;
    warehouse: Warehouse | null;
    sale_date: string;
    currency_code: string;
    held_at: string | null;
    lines: CartLine[];
    availability_warnings: Array<{ line_id: number; product_name: string; requested: string; available: string }>;
    requires_replenishment: boolean;
    remote_required: string;
    procurement_deficit?: boolean;
    awaiting_supplier_procurement?: boolean;
    procurements_count?: number;
    checkout: {
        global_discount_type: 'none' | 'fixed' | 'percentage';
        global_discount_value: string;
        global_discount_amount: string;
        fulfillment_mode: 'pickup' | 'delivery';
        shipping_fee: string;
        delivery_address: string | null;
        delivery_phone: string | null;
        delivery_notes: string | null;
    };
    summary: {
        merchandise_total: string;
        subtotal_excl_tax: string;
        line_discount_total: string;
        global_discount_amount: string;
        net_excl_tax: string;
        tax_total: string;
        shipping_fee: string;
        total: string;
    };
};
export type HeldSale = {
    id: number;
    order_number: string;
    held_at: string | null;
    customer_name: string | null;
    warehouse: Warehouse | null;
    product_count: number;
    line_count: number;
    subtotal: string;
    currency_code: string;
};
