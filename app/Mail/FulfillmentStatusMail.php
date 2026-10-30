<?php

namespace App\Mail;

use App\Models\FulfillmentAttachment;
use App\Models\FulfillmentCustomerNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** F1.2 "Votre commande … a été confiée au transporteur / livrée." — automatic or shared by the operator (optionally with the shipment proofs). */
class FulfillmentStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $shipment FulfillmentCustomerNotifier::content()
     * @param list<FulfillmentAttachment> $proofs
     */
    public function __construct(public readonly string $event, public readonly array $shipment, public readonly array $proofs = []) {}

    public function envelope(): Envelope
    {
        $key = $this->event === FulfillmentCustomerNotification::EVENT_DELIVERED ? 'delivered_subject' : 'handed_subject';

        return new Envelope(subject: __("fulfillment.email.{$key}", ['number' => $this->shipment['order_number']], config('documents.locale')));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.fulfillment.status', with: ['event' => $this->event, 'shipment' => $this->shipment, 'proofs' => $this->proofs]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(fn (FulfillmentAttachment $proof) => Attachment::fromStorageDisk($proof->disk, $proof->path)->as($proof->original_name)->withMime($proof->mime_type), $this->proofs);
    }
}
