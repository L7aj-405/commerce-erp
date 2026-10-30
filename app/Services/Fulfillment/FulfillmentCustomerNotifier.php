<?php

namespace App\Services\Fulfillment;

use App\Jobs\SendFulfillmentCustomerNotificationJob;
use App\Mail\FulfillmentStatusMail;
use App\Models\Fulfillment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Services\AuditLogger;
use App\Services\OrganizationOutboundMailService;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F1.2 customer notification of shipment events ("confiée au transporteur",
 * "livrée"). Logistics only: nothing here touches stock, invoices or payments.
 *
 * - request() runs inside the transition's transaction (Sales Order row lock
 *   held): it inserts the (shipment, event) row — the unique key is the
 *   persistent idempotency, so a retried / double-clicked transition never
 *   asks twice — and queues the job only after commit (a rolled-back
 *   transition, e.g. an MCP preview, sends nothing).
 * - deliver() is the job: claims pending → sending under a row lock, then
 *   sends through the organization's own SMTP (OrganizationOutboundMailService)
 *   outside any transaction, and records sent / skipped / failed. At most once:
 *   a claimed row is never re-sent, a failure is recorded, not retried.
 * - Only carrier shipments notify: a direct hand-over (counter, pickup, POS)
 *   is handed to the customer in person.
 * - Coexists with the operator's "Partager au client"
 *   (ShareFulfillmentWithCustomerAction): both record a content fingerprint,
 *   and the job skips (already_shared) when the operator already emailed the
 *   same content to the same address.
 */
final class FulfillmentCustomerNotifier
{
    /** Organization setting key; notifications are on unless explicitly turned off. */
    public const SETTING = 'fulfillment_customer_notifications';

    public function __construct(
        private readonly OrganizationOutboundMailService $mail,
        private readonly AuditLogger $audit,
    ) {}

    public static function enabledFor(Organization $organization): bool
    {
        $settings = is_array($organization->settings) ? $organization->settings : [];

        return ($settings[self::SETTING] ?? true) !== false;
    }

    public function request(Fulfillment $fulfillment, string $event): void
    {
        if ($fulfillment->channel !== Fulfillment::CHANNEL_CARRIER || ! in_array($event, FulfillmentCustomerNotification::EVENTS, true)) return;

        $now = now();
        $inserted = FulfillmentCustomerNotification::query()->insertOrIgnore([
            'organization_id' => $fulfillment->organization_id,
            'store_id' => $fulfillment->store_id,
            'sales_order_id' => $fulfillment->sales_order_id,
            'fulfillment_id' => $fulfillment->getKey(),
            'event' => $event,
            'origin' => FulfillmentCustomerNotification::ORIGIN_AUTOMATIC,
            'dedupe_key' => FulfillmentCustomerNotification::automaticKey($event),
            'channel' => FulfillmentCustomerNotification::CHANNEL_EMAIL,
            'status' => FulfillmentCustomerNotification::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) return;

        $id = FulfillmentCustomerNotification::query()->where('organization_id', $fulfillment->organization_id)
            ->where('fulfillment_id', $fulfillment->getKey())->where('dedupe_key', FulfillmentCustomerNotification::automaticKey($event))->value('id');
        SendFulfillmentCustomerNotificationJob::dispatch((int) $id)->afterCommit();
    }

    public function deliver(int $notificationId): void
    {
        $claim = DB::transaction(function () use ($notificationId) {
            $notification = FulfillmentCustomerNotification::query()->whereKey($notificationId)->lockForUpdate()->first();
            if (! $notification || $notification->status !== FulfillmentCustomerNotification::STATUS_PENDING) return null;

            $fulfillment = Fulfillment::query()->where('organization_id', $notification->organization_id)->whereKey($notification->fulfillment_id)
                ->with(['carrier:id,full_name,company_name,phone', 'salesOrder.customer:id,email', 'organization'])->firstOrFail();
            $order = $fulfillment->salesOrder;
            $recipient = $this->recipient($order);
            $hash = $recipient === null ? null : $this->fingerprint($fulfillment, $notification->event, FulfillmentCustomerNotification::CHANNEL_EMAIL, $recipient);
            $skip = match (true) {
                ! self::enabledFor($fulfillment->organization) => 'disabled',
                $recipient === null => 'no_customer_email',
                ! $this->mail->isConfigured($fulfillment->organization) => 'mail_not_configured',
                $this->alreadyEmailed($fulfillment, $hash, $notification->getKey()) !== null => 'already_shared',
                default => null,
            };
            $notification->recipient = $recipient;
            $notification->content_hash = $hash;
            if ($skip !== null) {
                // Recorded on the row (shown on the shipment), not audited: nothing was sent.
                $notification->forceFill(['status' => FulfillmentCustomerNotification::STATUS_SKIPPED, 'skip_reason' => $skip])->save();

                return null;
            }
            $notification->status = FulfillmentCustomerNotification::STATUS_SENDING;
            $notification->save();

            return [$notification, $fulfillment];
        });
        if ($claim === null) return;

        [$notification, $fulfillment] = $claim;
        try {
            $this->mail->send($fulfillment->organization, new FulfillmentStatusMail($notification->event, $this->content($fulfillment)), $notification->recipient);
            $notification->forceFill(['status' => FulfillmentCustomerNotification::STATUS_SENT, 'sent_at' => now()])->save();
            $this->record('fulfillment.customer_notified', $fulfillment, $notification, ['to' => $notification->recipient]);
        } catch (Throwable $exception) {
            $message = mb_substr(SensitiveDataRedactor::text($exception->getMessage() ?: $exception::class), 0, 500);
            $notification->forceFill(['status' => FulfillmentCustomerNotification::STATUS_FAILED, 'failure_message' => $message])->save();
            $this->record('fulfillment.customer_notification_failed', $fulfillment, $notification, ['to' => $notification->recipient, 'exception' => $exception::class]);
        }
    }

    /**
     * Same message, same address: a sent (or being sent) email of this shipment
     * with this fingerprint, automatic or shared by the operator.
     */
    public function alreadyEmailed(Fulfillment $fulfillment, ?string $hash, ?int $exceptId = null): ?FulfillmentCustomerNotification
    {
        if ($hash === null) return null;

        return FulfillmentCustomerNotification::query()->where('organization_id', $fulfillment->organization_id)->where('fulfillment_id', $fulfillment->getKey())
            ->where('channel', FulfillmentCustomerNotification::CHANNEL_EMAIL)->where('content_hash', $hash)
            ->whereIn('status', [FulfillmentCustomerNotification::STATUS_SENDING, FulfillmentCustomerNotification::STATUS_SENT])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->latest('id')->first();
    }

    /**
     * Fingerprint of what one message tells one recipient: channel, address,
     * event and the shared facts (carrier, tracking, packages, proofs). A
     * tracking fix or added proofs make a different message.
     *
     * @param list<string> $attachmentIds
     */
    public function fingerprint(Fulfillment $fulfillment, string $event, string $channel, string $recipient, array $attachmentIds = []): string
    {
        sort($attachmentIds);

        return hash('sha256', json_encode([
            $channel, mb_strtolower(trim($recipient)), $event, (string) $fulfillment->getKey(),
            $fulfillment->carrier_contact_id === null ? null : (int) $fulfillment->carrier_contact_id,
            $fulfillment->tracking_number, $fulfillment->tracking_url, $fulfillment->package_count === null ? null : (int) $fulfillment->package_count,
            array_values($attachmentIds),
        ]));
    }

    /** What the customer is told, read from the canonical rows at send time (a tracking fix before sending is included). @return array<string, mixed> */
    public function content(Fulfillment $fulfillment): array
    {
        $fulfillment->loadMissing(['carrier:id,full_name,company_name,phone', 'salesOrder', 'organization']);
        $order = $fulfillment->salesOrder;

        return [
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'seller_name' => $fulfillment->organization->name,
            'carrier' => $fulfillment->carrier ? ($fulfillment->carrier->company_name ?: $fulfillment->carrier->full_name) : null,
            'tracking_number' => $fulfillment->tracking_number,
            'tracking_url' => $fulfillment->tracking_url,
            'package_count' => $fulfillment->package_count,
            'handed_to_carrier_at' => $fulfillment->handed_to_carrier_at?->toIso8601String(),
            'delivered_at' => $fulfillment->delivered_at?->toIso8601String(),
        ];
    }

    /** The customer's email: the customer record first, then the order snapshot. */
    public function recipient(SalesOrder $order): ?string
    {
        foreach ([$order->customer?->email, $order->customer_email] as $email) {
            $email = is_string($email) ? trim($email) : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
        }

        return null;
    }

    /** @param array<string, mixed> $values */
    private function record(string $event, Fulfillment $fulfillment, FulfillmentCustomerNotification $notification, array $values): void
    {
        $this->audit->record($event, null, $fulfillment->organization, $fulfillment->salesOrder->store, $fulfillment, newValues: [
            'fulfillment_id' => (string) $fulfillment->getKey(),
            'sales_order_id' => (int) $fulfillment->sales_order_id,
            'order_number' => $fulfillment->salesOrder->order_number,
            'notification_event' => $notification->event,
            ...$values,
        ]);
    }
}
