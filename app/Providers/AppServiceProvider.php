<?php

namespace App\Providers;

use App\Contracts\ChallengeVerifier;
use App\Contracts\DnsResolver;
use App\Contracts\PdfGenerator;
use App\Services\ActiveTenantContext;
use App\Services\Pdf\DompdfPdfGenerator;
use App\Services\Security\NullChallengeVerifier;
use App\Services\Security\SystemDnsResolver;
use App\Services\Security\TenantSmtpTransportFactory;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ActiveTenantContext::class);
        $this->app->bind(PdfGenerator::class, DompdfPdfGenerator::class);
        // Tests bind a fake resolver (see Tests\TestCase::setUp()) so SSRF-guard
        // validation never performs a real DNS lookup for `.test`/`.example`
        // fixture hosts.
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        // Swap for a real hCaptcha/Turnstile/reCAPTCHA-backed implementation
        // once a provider is chosen — see the class doc on ChallengeVerifier.
        $this->app->bind(ChallengeVerifier::class, NullChallengeVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This app has no EventServiceProvider, so the framework's own
        // auto-registration of this listener (Foundation\EventServiceProvider)
        // never runs — wire it explicitly, or `event(new Registered($user))`
        // in RegisteredUserController silently sends nothing.
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        // Sprint 1.1 §9 — centralized password policy: a sensible modern
        // minimum length, no forced complexity rules that push users toward
        // predictable substitutions. Applies everywhere `Password::defaults()`
        // is used (registration, invitation acceptance, password reset).
        Password::defaults(fn () => Password::min(10));

        // A Fresh Auth challenge and its automatic resume both pass through
        // the execute route. Six attempts allow three complete challenge /
        // resume flows while keeping this privileged destructive endpoint
        // tightly limited per authenticated actor.
        RateLimiter::for('data-reset-execute', function (Request $request) {
            return Limit::perMinutes(10, 6)
                ->by('data-reset-execute:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'code' => 'DATA_RESET_RATE_LIMITED',
                    'message' => 'Too many organization data reset attempts. Try again later.',
                    'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                ], 429, $headers));
        });

        // Sprint 1.1 §1 — DNS-rebinding-resistant SMTP transport for TENANT
        // (organization-supplied) SMTP hosts only. See TenantSmtpTransportFactory
        // for why this must never be the platform's own `smtp` transport.
        Mail::extend('tenant_smtp', fn (array $config) => $this->app->make(TenantSmtpTransportFactory::class)->create($config));
    }
}
