<?php

namespace App\Jobs;

use App\Services\Fulfillment\FulfillmentCustomerNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one F1.2 shipment notification. Carries only the notification id; the
 * notifier reloads everything and claims the row under lock, so a duplicate
 * delivery (at-least-once queues, several workers) is a no-op. The tenant's
 * SMTP settings are resolved when the job runs, never serialized.
 */
class SendFulfillmentCustomerNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $notificationId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(FulfillmentCustomerNotifier $notifier): void
    {
        $notifier->deliver($this->notificationId);
    }
}
