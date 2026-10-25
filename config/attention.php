<?php

/*
| W4.1 Attention Engine — canonical thresholds (organization-local calendar
| days). No per-organization customization yet.
*/
return [
    'quotations' => [
        // Last successful email send at least this many local days ago, still undecided.
        'sent_undecided_days' => 3,
        // valid_until within this many local days (today included), not yet past.
        'expiring_soon_days' => 2,
        // Quotation-linked open overdue follow-ups: at least this many …
        'followup_backlog_count' => 2,
        // … or one overdue for at least this many local days.
        'followup_backlog_age_days' => 3,
        // Backlog becomes critical from this many overdue items or days.
        'followup_backlog_critical_count' => 4,
        'followup_backlog_critical_age_days' => 7,
    ],

    // W4.4: issued invoices with an outstanding balance (FinanceInvoiceReadModel).
    'invoices' => [
        // due_date within this many local days (today included), not yet past.
        'due_soon_days' => 2,
        'followup_backlog_count' => 2,
        'followup_backlog_age_days' => 3,
        'followup_backlog_critical_count' => 4,
        'followup_backlog_critical_age_days' => 7,
    ],
];
