<?php

use App\Http\Controllers\Mcp\OAuth\McpOAuthEndpointController;
use Illuminate\Support\Facades\Route;

/*
 * Stateless OAuth 2.1 / discovery endpoints for remote MCP clients (no
 * session, no cookies, no CSRF). The interactive consent screen lives in
 * routes/web.php behind the normal authenticated session.
 */

Route::match(['GET', 'OPTIONS'], '/.well-known/oauth-protected-resource', [McpOAuthEndpointController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
Route::match(['GET', 'OPTIONS'], '/.well-known/oauth-protected-resource/mcp', [McpOAuthEndpointController::class, 'protectedResource'])->name('mcp.oauth.protected-resource.mcp');
Route::match(['GET', 'OPTIONS'], '/.well-known/oauth-authorization-server', [McpOAuthEndpointController::class, 'authorizationServer'])->name('mcp.oauth.authorization-server');

Route::match(['POST', 'OPTIONS'], '/oauth/register', [McpOAuthEndpointController::class, 'register'])
    ->middleware('throttle:mcp-oauth-register')->name('mcp.oauth.register');
Route::match(['POST', 'OPTIONS'], '/oauth/token', [McpOAuthEndpointController::class, 'token'])
    ->middleware('throttle:mcp-oauth-token')->name('mcp.oauth.token');
Route::match(['POST', 'OPTIONS'], '/oauth/revoke', [McpOAuthEndpointController::class, 'revoke'])
    ->middleware('throttle:mcp-oauth-token')->name('mcp.oauth.revoke');
