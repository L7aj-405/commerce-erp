<?php

return [
    'success' => 'Demande :number réceptionnée : le stock a été transféré.',
    'not_shipped' => 'Seule une demande expédiée peut être réceptionnée.',
    'changed' => 'Cette demande a été modifiée entre-temps. Actualisez la page puis réessayez.',
    'order_not_confirmed' => 'La commande client liée n’est plus confirmée : réception impossible. Contactez un responsable.',
    'allocation_mismatch' => 'La quantité client de ce transfert ne correspond pas aux allocations de la commande à la source. Contactez un responsable avant de réceptionner.',
    'reservation_inconsistent' => 'La réservation de la commande est incohérente avec le stock. Contactez un responsable avant de réceptionner.',
    'insufficient_to_hold' => 'Stock libre insuffisant à :warehouse pour réserver :needed unité(s) de :sku pour la commande (:available disponible(s)). Libérez ou ajoutez du stock puis réessayez.',
    'insufficient_source_stock' => 'Stock disponible insuffisant à :warehouse pour effectuer ce transfert. Vérifiez le stock puis réessayez.',
];
