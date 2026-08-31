<?php

namespace App\Providers;

use App\Contracts\PdfGenerator;
use App\Services\ActiveTenantContext;
use App\Services\Pdf\DompdfPdfGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ActiveTenantContext::class);
        $this->app->bind(PdfGenerator::class, DompdfPdfGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
