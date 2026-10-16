<?php

namespace App\Services;

use App\Models\DocumentStampApposition;
use App\Models\Organization;
use App\Models\OrganizationDocumentStamp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Central filesystem boundary for company-stamp assets.
 *
 * New uploads use the persistent public disk, but they are never rendered by
 * Dompdf through an HTTP URL. Renderers receive an embedded data URI instead.
 * The legacy local disk remains readable for rows created before the storage
 * migration.
 */
class DocumentStampStorage
{
    private const ALLOWED_DISKS = ['public', 'local'];

    /** @return array{disk:string,path:string}|null */
    public function store(UploadedFile $file, Organization $organization): ?array
    {
        $disk = $this->configuredDisk();
        $path = $file->store('organization-stamps/'.$organization->getKey(), $disk);

        return is_string($path) && $path !== '' ? ['disk' => $disk, 'path' => $path] : null;
    }

    public function configuredDisk(): string
    {
        $disk = (string) config('documents.stamp.disk', 'public');

        return in_array($disk, self::ALLOWED_DISKS, true) ? $disk : 'public';
    }

    public function exists(OrganizationDocumentStamp|DocumentStampApposition $stamp): bool
    {
        return $this->bytes($stamp->image_path, $this->diskFor($stamp)) !== null;
    }

    public function dataUriForStamp(OrganizationDocumentStamp $stamp): ?string
    {
        return $this->dataUri($stamp->image_path, $stamp->mime_type, $this->diskFor($stamp));
    }

    public function dataUriForApposition(DocumentStampApposition $apposition): ?string
    {
        $snapshot = trim((string) ($apposition->image_data_uri ?? ''));
        if ($this->isSafeImageDataUri($snapshot)) {
            return $snapshot;
        }

        return $this->dataUri($apposition->image_path, $apposition->mime_type, $this->diskFor($apposition));
    }

    public function bytesForStamp(OrganizationDocumentStamp $stamp): ?string
    {
        return $this->bytes($stamp->image_path, $this->diskFor($stamp));
    }

    /** Remove only a newly-uploaded asset inside this organization's prefix. */
    public function removeOwnedUpload(string $disk, string $path, Organization $organization): void
    {
        if (! in_array($disk, self::ALLOWED_DISKS, true)
            || ! str_starts_with($path, 'organization-stamps/'.$organization->getKey().'/')) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Cleanup must never hide the original persistence exception.
        }
    }

    private function dataUri(string $path, string $mimeType, string $disk): ?string
    {
        if (! in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        $bytes = $this->bytes($path, $disk);

        return $bytes === null ? null : 'data:'.$mimeType.';base64,'.base64_encode($bytes);
    }

    private function bytes(string $path, string $disk): ?string
    {
        if ($path === '' || ! in_array($disk, self::ALLOWED_DISKS, true)) {
            return null;
        }

        try {
            $filesystem = Storage::disk($disk);

            return $filesystem->exists($path) ? $filesystem->get($path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function diskFor(OrganizationDocumentStamp|DocumentStampApposition $stamp): string
    {
        $disk = (string) ($stamp->storage_disk ?? 'local');

        return in_array($disk, self::ALLOWED_DISKS, true) ? $disk : 'local';
    }

    private function isSafeImageDataUri(string $value): bool
    {
        return preg_match('/^data:image\/(?:png|jpeg|webp);base64,[A-Za-z0-9+\/=\r\n]+$/', $value) === 1;
    }
}
