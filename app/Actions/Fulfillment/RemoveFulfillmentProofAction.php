<?php

namespace App\Actions\Fulfillment;

use App\Models\Fulfillment;
use App\Models\FulfillmentAttachment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Removes one proof (row first, then its file after commit). */
class RemoveFulfillmentProofAction
{
    public function __construct(private readonly FulfillmentEngine $engine) {}

    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, FulfillmentAttachment $attachment): void
    {
        $this->engine->authorize($actor, $order, 'fulfillment.manage');

        DB::transaction(function () use ($actor, $order, $fulfillment, $attachment) {
            $order = $this->engine->lockOrder($order);
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment);
            $attachment = FulfillmentAttachment::query()->where('organization_id', $order->organization_id)
                ->where('fulfillment_id', $fulfillment->getKey())->whereKey($attachment->getKey())->lockForUpdate()->firstOrFail();
            $attachment->delete();
            $this->engine->record('fulfillment.proof_removed', $actor, $order, $fulfillment, [
                'attachment_id' => (string) $attachment->getKey(), 'category' => $attachment->category, 'file_name' => $attachment->original_name,
            ], []);
            DB::afterCommit(fn () => Storage::disk($attachment->disk)->delete($attachment->path));
        });
    }
}
