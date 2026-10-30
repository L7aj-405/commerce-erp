<?php

namespace App\Services\Fulfillment;

use Carbon\CarbonImmutable;

/** Weekend-only working-day rule approved for F1.3 (no invented holidays). */
final class ShipmentFollowUpSchedule
{
    public function suggestedDate(CarbonImmutable $sharedAt): CarbonImmutable
    {
        if ($sharedAt->dayOfWeekIso >= 5) {
            return $sharedAt->next(CarbonImmutable::MONDAY)->startOfDay();
        }

        $date = $sharedAt;
        $remaining = 2;
        while ($remaining > 0) {
            $date = $date->addDay();
            if ($date->isWeekday()) $remaining--;
        }

        return $date->startOfDay();
    }
}
