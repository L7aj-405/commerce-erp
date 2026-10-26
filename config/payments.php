<?php

return [
    /*
     * W4.5 receivables: a Sales Order's due_date is stamped once, at
     * confirmation, as sale_date + this many calendar days. Changing it never
     * moves the due date of an already confirmed order.
     */
    'sales_order_payment_term_days' => (int) env('SALES_ORDER_PAYMENT_TERM_DAYS', 7),

    'account_types' => [
        'cash' => ['cash'],
        'card' => ['card_clearing', 'bank'],
        'bank_transfer' => ['bank'],
        'cheque' => ['cheque_clearing'],
    ],
];
