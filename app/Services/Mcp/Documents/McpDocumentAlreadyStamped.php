<?php

namespace App\Services\Mcp\Documents;

use RuntimeException;

/** The document already carries its (single) company stamp apposition: nothing new is created. */
final class McpDocumentAlreadyStamped extends RuntimeException
{
    public function __construct(
        public readonly string $documentType,
        public readonly int $documentId,
        public readonly ?string $appliedAt,
    ) {
        parent::__construct('document_already_stamped');
    }
}
