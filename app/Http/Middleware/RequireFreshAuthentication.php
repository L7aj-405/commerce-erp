<?php

namespace App\Http\Middleware;

use App\Services\Security\FreshAuthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireFreshAuthentication
{
    public function __construct(private readonly FreshAuthentication $freshAuthentication) {}

    public function handle(Request $request, Closure $next, string $level = '1'): Response
    {
        if ($level === 'email-change' && mb_strtolower((string) $request->input('email')) === mb_strtolower((string) $request->user()?->email)) {
            return $next($request);
        }

        $requiredLevel = $level === 'email-change' ? FreshAuthentication::LEVEL_ACCOUNT : (int) $level;
        $this->freshAuthentication->ensure($request, $requiredLevel);

        return $next($request);
    }
}
