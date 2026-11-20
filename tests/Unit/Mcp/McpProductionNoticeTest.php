<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\McpProductionNotice;
use PHPUnit\Framework\TestCase;

class McpProductionNoticeTest extends TestCase
{
    public function test_only_write_previews_are_annotated_and_existing_keys_are_kept(): void
    {
        $data = ['valid' => true, 'preview_fingerprint' => 'abc'];

        foreach (['preview_payment', 'preview_quotation_issue', 'prepare_product_bulk_update'] as $tool) {
            $annotated = McpProductionNotice::annotate($tool, $data);
            $this->assertSame(McpProductionNotice::MESSAGE, $annotated['production_warning'], $tool);
            $this->assertSame('abc', $annotated['preview_fingerprint']);
        }

        foreach (['get_customer', 'search_products', 'record_payment', 'issue_invoice'] as $tool) {
            $this->assertSame($data, McpProductionNotice::annotate($tool, $data), $tool);
        }
    }
}
