<?php

namespace App\Exceptions\Mcp;

use RuntimeException;

/** Signals the queue to retry a chunk after a transient infrastructure failure. */
final class McpTransientOperationException extends RuntimeException {}
