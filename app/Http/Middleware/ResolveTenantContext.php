<?php

namespace App\Http\Middleware;

use App\Services\ActiveTenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->resolve($request->user());

        return $next($request);
    }
}
