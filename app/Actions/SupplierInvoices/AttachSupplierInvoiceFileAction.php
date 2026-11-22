<?php

namespace App\Actions\SupplierInvoices;

use App\Actions\SupplierInvoices\Concerns\AuthorizesSupplierInvoiceAction;
use App\Enums\SupplierInvoiceStatus;
use App\Models\Organization;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SupplierInvoices\SupplierInvoiceStoreAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores a scan / photo of the supplier's invoice on the private attachments disk under a random key (the client
 * file name is display metadata only). Allowed while the invoice is a draft or approved, never when void. The type is
 * decided from the file CONTENT (finfo), never from the client MIME or extension. A row is only written once its file
 * is stored; any failure removes the stored file. The same file twice on one invoice is a 422; the same file on another
 * non-void invoice is a soft warning returned to the caller.
 */
class AttachSupplierInvoiceFileAction
{
    use AuthorizesSupplierInvoiceAction;

    public function __construct(private readonly AuditLogger $audit, private readonly SupplierInvoiceStoreAccess $stores) {}

    /** @return array{attachment: SupplierInvoiceAttachment, warnings: list<array{code: string, message: string}>} */
    public function execute(User $actor, Organization $organization, SupplierInvoice $invoice, UploadedFile $file): array
    {
        $this->assertInvoiceAccess($actor, $organization, 'supplier_invoices.manage');
        if ((int) $invoice->organization_id !== (int) $organization->getKey()) {
            abort(404);
        }
        $store = $invoice->store;
        $this->assertStoreAccess($actor, $store, 'canEdit');

        $mime = $this->validatedMime($file);
        $extension = (string) config("supplier_invoices.attachments.mime_types.{$mime}");
        $hash = (string) hash_file('sha256', $file->getRealPath());
        $disk = (string) config('supplier_invoices.attachments.disk', 'local');
        $stored = null;

        try {
            return DB::transaction(function () use ($actor, $organization, $invoice, $file, $mime, $extension, $hash, $disk, &$stored) {
                $locked = SupplierInvoice::query()->where('organization_id', $organization->getKey())->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->status === SupplierInvoiceStatus::Void) {
                    throw ValidationException::withMessages(['file' => 'Une facture fournisseur annulée n’accepte plus de pièce jointe.']);
                }
                $existing = SupplierInvoiceAttachment::query()->where('organization_id', $organization->getKey())->where('supplier_invoice_id', $locked->getKey());
                if ((clone $existing)->count() >= (int) config('supplier_invoices.attachments.max_files', 10)) {
                    throw ValidationException::withMessages(['file' => 'Nombre maximal de pièces jointes atteint pour cette facture.']);
                }
                if ((clone $existing)->where('sha256', $hash)->exists()) {
                    throw ValidationException::withMessages(['file' => 'Ce fichier est déjà joint à cette facture.']);
                }

                $path = $file->storeAs(
                    "supplier-invoices/{$organization->getKey()}/{$locked->getKey()}",
                    Str::lower((string) Str::ulid()).'.'.$extension,
                    $disk,
                );
                if ($path === false) {
                    throw new \RuntimeException('Supplier invoice attachment could not be stored.');
                }
                $stored = $path;

                $attachment = new SupplierInvoiceAttachment;
                $attachment->organization_id = $organization->getKey();
                $attachment->supplier_invoice_id = $locked->getKey();
                $attachment->disk = $disk;
                $attachment->storage_key = $path;
                $attachment->original_name = $this->displayName($file, $extension);
                $attachment->mime = $mime;
                $attachment->size_bytes = (int) $file->getSize();
                $attachment->sha256 = $hash;
                $attachment->uploaded_by_user_id = $actor->getKey();
                $attachment->save();

                $this->audit->record('supplier_invoice.attachment_added', $actor, $organization, $locked->store, $locked, newValues: [
                    'attachment_id' => $attachment->getKey(), 'file_name' => $attachment->original_name, 'mime' => $mime,
                    'size_bytes' => $attachment->size_bytes, 'sha256' => $hash, 'invoice_status' => $locked->status->value,
                ]);

                return ['attachment' => $attachment, 'warnings' => $this->sameFileElsewhere($actor, $organization, $locked, $hash)];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->discard($disk, $stored);
            throw ValidationException::withMessages(['file' => 'Ce fichier est déjà joint à cette facture.']);
        } catch (Throwable $exception) {
            $this->discard($disk, $stored);
            throw $exception;
        }
    }

    /** Content-sniffed MIME (never the client one), size and allowlist checks. */
    private function validatedMime(UploadedFile $file): string
    {
        if (! $file->isValid() || $file->getRealPath() === false) {
            throw ValidationException::withMessages(['file' => 'Le fichier n’a pas pu être téléversé.']);
        }
        if ($file->getSize() > (int) config('supplier_invoices.attachments.max_kb', 10240) * 1024) {
            throw ValidationException::withMessages(['file' => 'Le fichier dépasse la taille maximale autorisée.']);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $file->getRealPath()) : '';
        if (! array_key_exists($mime, (array) config('supplier_invoices.attachments.mime_types'))) {
            throw ValidationException::withMessages(['file' => 'Type de fichier non autorisé (PDF, JPEG, PNG ou WebP uniquement).']);
        }

        return $mime;
    }

    /** Display-only: path components and unusual characters removed. */
    private function displayName(UploadedFile $file, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = (string) preg_replace('/[^\pL\pN ._()-]+/u', '_', $name);
        $name = ltrim(trim($name), '.');

        return mb_substr($name !== '' ? $name : 'document.'.$extension, 0, 200);
    }

    /**
     * Soft warning: the same file is already attached to another non-void invoice the actor may see.
     *
     * @return list<array{code: string, message: string}>
     */
    private function sameFileElsewhere(User $actor, Organization $organization, SupplierInvoice $invoice, string $hash): array
    {
        $visible = $this->stores->visibleStoreIds($actor, $organization);
        $others = SupplierInvoice::query()->where('organization_id', $organization->getKey())
            ->whereKeyNot($invoice->getKey())->where('status', '!=', SupplierInvoiceStatus::Void->value)
            ->whereIn('store_id', $visible)
            ->whereIn('id', SupplierInvoiceAttachment::query()->where('organization_id', $organization->getKey())->where('sha256', $hash)->select('supplier_invoice_id'))
            ->orderBy('id')->limit(5)->pluck('internal_number');

        return $others->isEmpty() ? [] : [[
            'code' => 'attachment_duplicate_file',
            'message' => 'Ce fichier est déjà joint à : '.$others->implode(', ').'. Vérifiez qu’il ne s’agit pas d’un doublon.',
        ]];
    }

    private function discard(string $disk, ?string $path): void
    {
        if ($path !== null) {
            Storage::disk($disk)->delete($path);
        }
    }
}
