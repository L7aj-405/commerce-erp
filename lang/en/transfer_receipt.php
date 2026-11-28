<?php

return [
    'success' => 'Request :number received: the stock has been transferred.',
    'not_shipped' => 'Only a shipped request can be received.',
    'changed' => 'This request was changed in the meantime. Refresh the page and try again.',
    'order_not_confirmed' => 'The linked customer order is no longer confirmed, so this request cannot be received. Contact a manager.',
    'allocation_mismatch' => 'The customer quantity on this transfer does not match the order’s allocations at the source. Contact a manager before receiving.',
    'reservation_inconsistent' => 'The order reservation does not match the stock records. Contact a manager before receiving.',
    'insufficient_to_hold' => 'Not enough free stock at :warehouse to reserve :needed unit(s) of :sku for the order (:available available). Free or add stock, then try again.',
    'insufficient_source_stock' => 'Not enough available stock at :warehouse to carry out this transfer. Check the stock, then try again.',
];
