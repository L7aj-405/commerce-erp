<?php

namespace App\Services\OrganizationDataReset;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrganizationDataResetFileCleanup
{
    /** @param list<array{disk:string,path:string}> $files */
    public function afterCommit(array $files): void
    {
        if ($files === []) {
            return;
        }

        DB::afterCommit(function () use ($files): void {
            foreach ($files as $file) {
                Storage::disk($file['disk'])->delete($file['path']);
            }
        });
    }
}
