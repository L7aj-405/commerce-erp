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
        // Phase 4: Production inventory tables are unreachable from Test requests.
        foreach (\Illuminate\Support\Facades\DB::getConnections() as $connection) {
            \App\Services\Sandbox\SandboxInventoryGuard::register($connection);
        }
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class,
            fn ($event) => \App\Services\Sandbox\SandboxInventoryGuard::register($event->connection));
        Route::middleware(['web', 'auth', 'two-factor.policy'])->prefix('test-mode')->name('sandbox.')->group(function () {
            Route::get('/', [SandboxController::class, 'show'])->name('show');
            Route::post('/enter', [SandboxController::class, 'enter'])->name('enter');
            Route::post('/leave', [SandboxController::class, 'leave'])->name('leave');
            Route::post('/policy', [SandboxController::class, 'policy'])->name('policy');
            // Phase 9: dedicated Test data reset (preview, then confirmed execution).
            Route::get('/reset', [\App\Http\Controllers\SandboxResetController::class, 'show'])->name('reset.show');
            Route::post('/reset', [\App\Http\Controllers\SandboxResetController::class, 'execute'])->name('reset.execute');
        });
    }
}
