<?php

namespace App\Services\OrganizationBackups;

final readonly class OrganizationBackupArchive
{
    /** @param array<string, int> $counts */
    public function __construct(
        public string $path,
        public string $filename,
        public array $manifest,
        public array $counts,
        public int $size,
    ) {}
}
