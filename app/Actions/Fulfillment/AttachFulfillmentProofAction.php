<?php

namespace App\Actions\Fulfillment;

use App\Models\Fulfillment;
use App\Models\FulfillmentAttachment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Stores shipping proofs / carrier documents (images or PDF) on the private
 * fulfillment disk under a random key — the client filename is only display
 * metadata. A row is written only once its file is stored; a failed write
 * removes the stored files.
 */
class AttachFulfillmentProofAction
{
    public function __construct(private readonly FulfillmentEngine $engine) {}

    /** @return array<string, mixed> */
    public static function fileRules(): array
    {
        return ['file', 'max:'.(int) config('fulfillment.attachments.max_kb', 10240), 'mimetypes:'.implode(',', array_keys((array) config('fulfillment.attachments.mime_types')))];
    }

    /** @param list<UploadedFile> $files @return list<FulfillmentAttachment> */
    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, array $files, string $category = 'shipping_proof'): array
    {
        $this->engine->authorizeAny($actor, $order, ['fulfillment.manage', 'fulfillment.dispatch']);
        Validator::make(['files' => $files, 'category' => $category], [
            'files' => ['required', 'array', 'min:1', 'max:'.(int) config('fulfillment.attachments.max_files', 5)],
            'files.*' => self::fileRules(),
            'category' => ['required', 'in:'.implode(',', FulfillmentAttachment::CATEGORIES)],
        ])->validate();
        $disk = (string) config('fulfillment.attachments.disk', 'local');
        $stored = [];

        try {
            return DB::transaction(function () use ($actor, $order, $fulfillment, $files, $category, $disk, &$stored) {
                $order = $this->engine->lockOrder($order);
                $fulfillment = $this->engine->lockFulfillment($order, $fulfillment);
                $attachments = [];
                foreach ($files as $file) {
                    $mime = (string) $file->getMimeType();
                    $extension = (string) config("fulfillment.attachments.mime_types.{$mime}");
                    $path = $file->storeAs("fulfillments/{$order->organization_id}/{$fulfillment->getKey()}", Str::lower((string) Str::ulid()).'.'.$extension, $disk);
                    if ($path === false) throw new \RuntimeException('Fulfillment attachment could not be stored.');
                    $stored[] = $path;
                    $attachment = new FulfillmentAttachment;
                    $attachment->forceFill([
                        'organization_id' => $order->organization_id,
                        'fulfillment_id' => $fulfillment->getKey(),
                        'category' => $category,
                        'disk' => $disk,
                        'path' => $path,
                        'original_name' => mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', $file->getClientOriginalName()) ?: 'document.'.$extension, 0, 200),
                        'mime_type' => $mime,
                        'size_bytes' => (int) $file->getSize(),
                        'uploaded_by_user_id' => $actor->getKey(),
                    ])->save();
                    $attachments[] = $attachment;
                    $this->engine->record('fulfillment.proof_attached', $actor, $order, $fulfillment, [], [
                        'attachment_id' => (string) $attachment->getKey(), 'category' => $category, 'file_name' => $attachment->original_name,
                        'mime_type' => $mime, 'size_bytes' => $attachment->size_bytes,
                    ]);
                }

                return $attachments;
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $path) Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }
}
