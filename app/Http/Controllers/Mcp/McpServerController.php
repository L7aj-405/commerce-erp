<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpServerFactory;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Request;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

final class McpServerController extends Controller
{
    public function __invoke(Request $request, McpServerFactory $servers): Response
    {
        $factory = new HttpFactory;
        $psrRequest = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request);
        $transport = new StreamableHttpTransport($psrRequest, middleware: [
            new CorsMiddleware(allowedOrigins: config('mcp.allowed_origins', [])),
            new DnsRebindingProtectionMiddleware(
                allowedHosts: config('mcp.allowed_hosts', ['localhost']),
                allowedOrigins: config('mcp.allowed_origins', []),
            ),
        ]);
        $psrResponse = $servers->make()->run($transport);

        return (new HttpFoundationFactory)->createResponse($psrResponse);
    }
}
