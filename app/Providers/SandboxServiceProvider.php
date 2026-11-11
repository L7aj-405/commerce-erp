<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Services\Sandbox\SandboxContext;
use App\Http\Controllers\SandboxController;
class SandboxServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->scoped(SandboxContext::class); }
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'two-factor.policy'])->prefix('test-mode')->name('sandbox.')->group(function () {
            Route::get('/', [SandboxController::class, 'show'])->name('show');
            Route::post('/enter', [SandboxController::class, 'enter'])->name('enter');
            Route::post('/leave', [SandboxController::class, 'leave'])->name('leave');
            Route::post('/policy', [SandboxController::class, 'policy'])->name('policy');
        });
    }
}
