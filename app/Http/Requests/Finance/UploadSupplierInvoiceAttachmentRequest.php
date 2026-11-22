<?php

namespace App\Http\Requests\Finance;

use App\Models\SupplierInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** The file itself is validated (content-sniffed) by AttachSupplierInvoiceFileAction. */
class UploadSupplierInvoiceAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('supplierInvoice');

        return $invoice instanceof SupplierInvoice && Gate::forUser($this->user())->allows('view', $invoice);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['file.required' => 'Sélectionnez un fichier.', 'file.file' => 'Le fichier n’a pas pu être téléversé.'];
    }
}
