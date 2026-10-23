<?php

namespace App\Services\Mcp\Sales;

use RuntimeException;

/** Thrown to roll back a preview transaction after the real actions ran in it. */
final class McpDryRunRollback extends RuntimeException {}
