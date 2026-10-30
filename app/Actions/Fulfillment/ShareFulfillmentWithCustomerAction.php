<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Mail\FulfillmentStatusMail;
use App\Models\Fulfillment;
use App\Models\FulfillmentAttachment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fulfillment\FulfillmentCustomerNotifier;
use App\Services\Fulfillment\FulfillmentEngine;
use App\Services\OrganizationOutboundMailService;
use App\Services\WhatsAppContactLinkResolver;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Partager au client": the operator tells the customer that the order was
 * handed to the carrier and is on its way — carrier, tracking number / link,
 * packages and, if chosen, the shipment proofs.
 *
 * - Email: sent now through the organization's own SMTP
 *   (OrganizationOutboundMailService), the proofs attached.
 * - WhatsApp: the ERP has no WhatsApp sending API; as for invoices and
 *   quotations the operator opens a prefilled click-to-chat link (proofs as
 *   temporary signed links). Recorded as "opened" — never as "sent".
 *
 * Logistics only: no stock, reservation, order, invoice, payment, delivery
 * note or shipment status change. Retry-safe: the client operation id is the
 * persistent idempotency key (a retried submit returns the first outcome, it
 * never sends again). The same email content to the same address — automatic
 * or shared — needs an explicit resend; a still-queued automatic email to that
 * address is superseded (skipped as already_shared) so the customer gets one.
 */
class ShareFulfillmentWithCustomerAction
{
    /** Shareable once the goods left with the carrier and until delivery. */
    public const STATUSES = [FulfillmentStatus::HandedToCarrier, FulfillmentStatus::InTransit];

    public const FIELDS = ['channel', 'client_operation_id', 'attachment_ids', 'email', 'resend', 'phone', 'message'];

    public function __construct(
        private readonly FulfillmentEngine $engine,
        private readonly FulfillmentCustomerNotifier $notifier,
        private readonly OrganizationOutboundMailService $mail,
        private readonly WhatsAppContactLinkResolver $whatsapp,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'channel' => ['required', 'in:'.implode(',', FulfillmentCustomerNotification::CHANNELS)],
            'client_operation_id' => ['required', 'uuid'],
            'attachment_ids' => ['nullable', 'array', 'max:50'],
            'attachment_ids.*' => ['string', 'max:64'],
            'email' => ['exclude_unless:channel,email', 'required', 'email:rfc', 'max:254'],
            'resend' => ['sometimes', 'boolean'],
            'phone' => ['exclude_unless:channel,whatsapp', 'nullable', 'string', 'max:40'],
            'message' => ['exclude_unless:channel,whatsapp', 'required', 'string', 'max:4000'],
        ];
    }

    /**
     * What will be shared, for the dialog: the rendered email, the prefilled
     * WhatsApp message and destination, the proofs and the earlier messages.
     *
     * @param list<string>|null $attachmentIds null = every proof
     * @return array<string, mixed>
     */
    public function preview(User $actor, SalesOrder $order, Fulfillment $fulfillment, ?array $attachmentIds = null): array
    {
        $this->authorize($actor, $order);
        $fulfillment = $this->shipment($order, $fulfillment);
        $this->assertShareable($fulfillment);
        $proofs = $this->proofs($fulfillment);
        $selected = $attachmentIds === null ? $proofs : $this->selected($proofs, $attachmentIds);
        $content = $this->notifier->content($fulfillment);
        $message = $this->whatsappMessage($content, $selected);
        $email = $this->notifier->recipient($order);
        $previous = $email === null ? null : $this->notifier->alreadyEmailed($fulfillment, $this->hash($fulfillment, FulfillmentCustomerNotification::CHANNEL_EMAIL, $email, $selected));
        $mail = new FulfillmentStatusMail(FulfillmentCustomerNotification::EVENT_HANDED_TO_CARRIER, $content, $selected->values()->all());

        return [
            'channels' => ['email' => true, 'whatsapp' => true],
            'email' => [
                'to' => $email,
                'configured' => $this->mail->isConfigured($order->organization),
                'subject' => $mail->envelope()->subject,
                'html' => $mail->render(),
                'already_sent' => $previous ? ['to' => $previous->recipient, 'at' => ($previous->sent_at ?? $previous->created_at)?->toIso8601String(), 'origin' => $previous->origin] : null,
            ],
            'whatsapp' => [
                'message' => $message,
                'destination' => $this->whatsapp->resolve($order->customer_whatsapp_username, $order->customer_phone, $message),
                'phone' => $order->customer_phone,
            ],
            'proofs' => $proofs->map(fn (FulfillmentAttachment $proof) => [
                'id' => (string) $proof->getKey(), 'name' => $proof->original_name, 'mime_type' => $proof->mime_type,
                'selected' => $selected->contains(fn (FulfillmentAttachment $chosen) => $chosen->is($proof)),
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, array $data): FulfillmentCustomerNotification
    {
        $this->authorize($actor, $order);
        $data = Validator::make($data, self::rules())->validate();
        $key = FulfillmentCustomerNotification::shareKey($data['client_operation_id']);

        [$notification, $send] = $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $fulfillment, $data, $key) {
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment);
            $existing = FulfillmentCustomerNotification::query()->where('organization_id', $order->organization_id)
                ->where('fulfillment_id', $fulfillment->getKey())->where('dedupe_key', $key)->first();
            if ($existing) return [$existing, null];

            $this->assertShareable($fulfillment);
            $proofs = $this->selected($this->proofs($fulfillment), $data['attachment_ids'] ?? []);

            return $data['channel'] === FulfillmentCustomerNotification::CHANNEL_EMAIL
                ? $this->claimEmail($actor, $order, $fulfillment, $key, $data, $proofs)
                : [$this->recordWhatsApp($actor, $order, $fulfillment, $key, $data, $proofs), null];
        });

        if ($send !== null) $notification = $this->sendEmail($actor, $order, $notification, $send[0], $send[1]);
        if ($notification->status === FulfillmentCustomerNotification::STATUS_FAILED) {
            throw ValidationException::withMessages(['email' => 'L’e-mail n’a pas pu être envoyé. Vérifiez l’adresse et la messagerie sortante, puis réessayez.']);
        }

        return $notification;
    }

    /**
     * Inside the lock: refuse a duplicate without resend, supersede a still
     * queued automatic email to the same address, claim a "sending" row.
     *
     * @param array<string, mixed> $data
     * @param Collection<int, FulfillmentAttachment> $proofs
     * @return array{0: FulfillmentCustomerNotification, 1: array{0: Fulfillment, 1: Collection<int, FulfillmentAttachment>}}
     */
    private function claimEmail(User $actor, SalesOrder $order, Fulfillment $fulfillment, string $key, array $data, Collection $proofs): array
    {
        if (! $this->mail->isConfigured($order->organization)) {
            throw ValidationException::withMessages(['email' => 'La messagerie sortante de l’organisation n’est pas configurée.']);
        }
        $to = mb_strtolower(trim($data['email']));
        $hash = $this->hash($fulfillment, FulfillmentCustomerNotification::CHANNEL_EMAIL, $to, $proofs);
        $previous = $this->notifier->alreadyEmailed($fulfillment, $hash);
        if ($previous && ! ($data['resend'] ?? false)) {
            throw ValidationException::withMessages(['resend' => "Ce message a déjà été envoyé à {$previous->recipient}. Cochez « Renvoyer quand même » pour l’envoyer à nouveau."]);
        }

        // The operator's message carries the same facts (and maybe proofs): the queued automatic one would be a duplicate.
        $queued = FulfillmentCustomerNotification::query()->where('organization_id', $order->organization_id)->where('fulfillment_id', $fulfillment->getKey())
            ->where('dedupe_key', FulfillmentCustomerNotification::automaticKey(FulfillmentCustomerNotification::EVENT_HANDED_TO_CARRIER))
            ->where('status', FulfillmentCustomerNotification::STATUS_PENDING)->lockForUpdate()->first();
        if ($queued && mb_strtolower((string) $this->notifier->recipient($order)) === $to) {
            $queued->forceFill(['status' => FulfillmentCustomerNotification::STATUS_SKIPPED, 'skip_reason' => 'already_shared', 'recipient' => $to])->save();
        }

        $notification = $this->row($actor, $fulfillment, $key, FulfillmentCustomerNotification::CHANNEL_EMAIL, FulfillmentCustomerNotification::STATUS_SENDING, $to, $hash, null, $proofs);

        return [$notification, [$fulfillment, $proofs]];
    }

    /** @param Collection<int, FulfillmentAttachment> $proofs */
    private function sendEmail(User $actor, SalesOrder $order, FulfillmentCustomerNotification $notification, Fulfillment $fulfillment, Collection $proofs): FulfillmentCustomerNotification
    {
        $values = ['channel' => 'email', 'to' => $notification->recipient, 'proofs' => $proofs->count()];
        try {
            $this->mail->send($order->organization, new FulfillmentStatusMail($notification->event, $this->notifier->content($fulfillment), $proofs->values()->all()), $notification->recipient);
            $notification->forceFill(['status' => FulfillmentCustomerNotification::STATUS_SENT, 'sent_at' => now()])->save();
            $this->recordShared($actor, $order, $fulfillment, 'fulfillment.shared_with_customer', 'sales_order.shipment_shared_email', $values);
        } catch (Throwable $exception) {
            $message = mb_substr(SensitiveDataRedactor::text($exception->getMessage() ?: $exception::class), 0, 500);
            $notification->forceFill(['status' => FulfillmentCustomerNotification::STATUS_FAILED, 'failure_message' => $message])->save();
            $this->engine->record('fulfillment.share_failed', $actor, $order, $fulfillment, [], [...$values, 'exception' => $exception::class]);
        }

        return $notification;
    }

    /**
     * @param array<string, mixed> $data
     * @param Collection<int, FulfillmentAttachment> $proofs
     */
    private function recordWhatsApp(User $actor, SalesOrder $order, Fulfillment $fulfillment, string $key, array $data, Collection $proofs): FulfillmentCustomerNotification
    {
        $destination = $this->whatsapp->resolve($order->customer_whatsapp_username, filled($data['phone'] ?? null) ? $data['phone'] : $order->customer_phone);
        if (! ($destination['available'] ?? false)) {
            throw ValidationException::withMessages(['phone' => 'Aucun numéro ou identifiant WhatsApp valide pour ce client.']);
        }
        $to = ($destination['type'] === 'username' ? '@' : '+').ltrim((string) $destination['destination'], '@+');
        $message = trim($data['message']);
        $hash = $this->hash($fulfillment, FulfillmentCustomerNotification::CHANNEL_WHATSAPP, $to, $proofs);
        $notification = $this->row($actor, $fulfillment, $key, FulfillmentCustomerNotification::CHANNEL_WHATSAPP, FulfillmentCustomerNotification::STATUS_OPENED, $to, $hash, $message, $proofs, now());
        $this->recordShared($actor, $order, $fulfillment, 'fulfillment.shared_with_customer', 'sales_order.shipment_shared_whatsapp', ['channel' => 'whatsapp', 'to' => $to, 'proofs' => $proofs->count()]);

        return $notification;
    }

    /** @param Collection<int, FulfillmentAttachment> $proofs */
    private function row(User $actor, Fulfillment $fulfillment, string $key, string $channel, string $status, string $to, string $hash, ?string $message, Collection $proofs, mixed $sentAt = null): FulfillmentCustomerNotification
    {
        $notification = new FulfillmentCustomerNotification;
        $notification->forceFill([
            'organization_id' => $fulfillment->organization_id,
            'store_id' => $fulfillment->store_id,
            'sales_order_id' => $fulfillment->sales_order_id,
            'fulfillment_id' => $fulfillment->getKey(),
            'event' => FulfillmentCustomerNotification::EVENT_HANDED_TO_CARRIER,
            'origin' => FulfillmentCustomerNotification::ORIGIN_MANUAL,
            'dedupe_key' => $key,
            'channel' => $channel,
            'status' => $status,
            'recipient' => $to,
            'content_hash' => $hash,
            'message' => $message,
            'attachment_ids' => $proofs->map(fn (FulfillmentAttachment $proof) => (string) $proof->getKey())->values()->all(),
            'sent_by_user_id' => $actor->getKey(),
            'sent_at' => $sentAt,
        ])->save();

        return $notification;
    }

    /**
     * Audited on the shipment (fulfillment history) and on the Sales Order,
     * whose business timeline shows it.
     *
     * @param array<string, mixed> $values
     */
    private function recordShared(User $actor, SalesOrder $order, Fulfillment $fulfillment, string $event, string $timelineEvent, array $values): void
    {
        $this->engine->record($event, $actor, $order, $fulfillment, [], $values);
        $this->audit->record($timelineEvent, $actor, $order->organization, $order->store, $order, newValues: [
            'fulfillment_id' => (string) $fulfillment->getKey(), 'order_number' => $order->order_number, ...$values,
        ]);
    }

    /** @param array<string, mixed> $content @param Collection<int, FulfillmentAttachment> $proofs */
    private function whatsappMessage(array $content, Collection $proofs): string
    {
        $name = trim((string) ($content['customer_name'] ?? ''));
        $ttl = now()->addDays((int) config('documents.share_link_ttl_days', 14));
        $line = fn (string $key, mixed $value) => filled($value) ? __("fulfillment.email.{$key}", locale: config('documents.locale')).' : '.$value : null;

        return implode("\n", array_filter([
            $name !== '' ? "Bonjour {$name}," : 'Bonjour,',
            __('fulfillment.email.handed_body', ['number' => $content['order_number']], config('documents.locale')).' '.__('fulfillment.email.on_its_way', locale: config('documents.locale')),
            $line('carrier', $content['carrier']),
            $line('tracking_number', $content['tracking_number']),
            $line('tracking_url', $content['tracking_url']),
            $line('package_count', $content['package_count']),
            ...$proofs->map(fn (FulfillmentAttachment $proof) => $line('proof_link', URL::temporarySignedRoute('fulfillments.shared-proof', $ttl, ['attachment' => $proof->getKey()])))->all(),
            $content['seller_name'],
        ]));
    }

    private function authorize(User $actor, SalesOrder $order): void
    {
        $this->engine->authorizeAny($actor, $order, ['fulfillment.manage', 'fulfillment.dispatch']);
    }

    private function shipment(SalesOrder $order, Fulfillment $fulfillment): Fulfillment
    {
        return Fulfillment::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())->whereKey($fulfillment->getKey())->firstOrFail();
    }

    private function assertShareable(Fulfillment $fulfillment): void
    {
        if ($fulfillment->channel !== Fulfillment::CHANNEL_CARRIER || ! in_array($fulfillment->status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Seule une expédition remise au transporteur (ou en transit) se partage au client.']);
        }
    }

    /** @return Collection<int, FulfillmentAttachment> */
    private function proofs(Fulfillment $fulfillment): Collection
    {
        return FulfillmentAttachment::query()->where('organization_id', $fulfillment->organization_id)->where('fulfillment_id', $fulfillment->getKey())
            ->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (FulfillmentAttachment $proof) => Storage::disk($proof->disk)->exists($proof->path))->values();
    }

    /** @param Collection<int, FulfillmentAttachment> $proofs @param list<string> $ids @return Collection<int, FulfillmentAttachment> */
    private function selected(Collection $proofs, array $ids): Collection
    {
        $ids = array_map(fn ($id) => strtolower((string) $id), $ids);
        $unknown = array_diff($ids, $proofs->map(fn (FulfillmentAttachment $proof) => strtolower((string) $proof->getKey()))->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['attachment_ids' => 'Justificatif inconnu pour cette expédition.']);
        }

        return $proofs->filter(fn (FulfillmentAttachment $proof) => in_array(strtolower((string) $proof->getKey()), $ids, true))->values();
    }

    /** @param Collection<int, FulfillmentAttachment> $proofs */
    private function hash(Fulfillment $fulfillment, string $channel, string $to, Collection $proofs): string
    {
        return $this->notifier->fingerprint($fulfillment, FulfillmentCustomerNotification::EVENT_HANDED_TO_CARRIER, $channel, $to,
            $proofs->map(fn (FulfillmentAttachment $proof) => strtolower((string) $proof->getKey()))->values()->all());
    }
}
