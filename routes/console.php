<?php

use App\Models\ProductImport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('catalog:cleanup-product-imports', function () {
    $count = ProductImport::query()->where('expires_at', '<', now())->delete();
    $this->info("Deleted {$count} expired product import staging records.");
})->purpose('Delete expired product import staging and history');

Schedule::command('catalog:cleanup-product-imports')->daily();
