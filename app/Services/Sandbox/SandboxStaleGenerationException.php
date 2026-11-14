<?php

namespace App\Services\Sandbox;

use RuntimeException;

/**
 * A Test request bound to an old sandbox generation tried to write Test data
 * after a reset (or after the sandbox stopped being active). Nothing was
 * written; the client must re-enter Test Mode.
 */
final class SandboxStaleGenerationException extends RuntimeException
{
    public const MESSAGE = 'Le Mode Test a été réinitialisé. Veuillez entrer à nouveau en Mode Test.';

    public function __construct(
        public readonly int $sandboxId,
        public readonly int $requestGeneration,
        public readonly ?int $currentGeneration,
    ) {
        parent::__construct(self::MESSAGE);
    }
}
