<?php

namespace App\Services\Sandbox;

use App\Models\Organization;
use App\Support\LockedRow;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Non-official references for Test documents (TEST-FAC-000001, TEST-BL-…,
 * TEST-AV-…). Uses its own locked counter rows in sandbox_document_sequences
 * and never reads or writes Production numbering tables.
 */
final class SandboxDocumentNumberGenerator
{
    public const INVOICE = 'invoice';

    public const DELIVERY_NOTE = 'delivery_note';

    public const CREDIT_NOTE = 'credit_note';

    public const STOCK_TRANSFER = 'stock_transfer';

    private const PREFIXES = [
        self::INVOICE => 'TEST-FAC-',
        self::DELIVERY_NOTE => 'TEST-BL-',
        self::CREDIT_NOTE => 'TEST-AV-',
        self::STOCK_TRANSFER => 'TEST-TRF-',
    ];

    public function next(Organization $organization, string $type): string
    {
        $prefix = self::PREFIXES[$type] ?? throw new InvalidArgumentException("Unknown Test document type [{$type}].");
        if (! app(SandboxContext::class)->isSandbox()) {
            throw new LogicException('Test document references may only be allocated in Test Mode.');
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Test document references may only be allocated inside an issuance transaction.');
        }

        $identity = ['organization_id' => $organization->getKey(), 'document_type' => $type];
        LockedRow::claim('sandbox_document_sequences', $identity, ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $number = (int) DB::table('sandbox_document_sequences')->where($identity)->lockForUpdate()->value('next_number');
        DB::table('sandbox_document_sequences')->where($identity)->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
