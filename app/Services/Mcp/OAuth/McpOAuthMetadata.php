<?php

namespace App\Services\Mcp\OAuth;

/**
 * Discovery documents for remote MCP clients:
 *  - RFC 9728 Protected Resource Metadata for the /mcp resource
 *  - RFC 8414 Authorization Server Metadata
 * Everything derives from APP_URL; nothing is client-specific.
 */
final class McpOAuthMetadata
{
    public function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** Canonical MCP resource identifier (RFC 8707 audience). */
    public function resource(): string
    {
        return $this->issuer().'/mcp';
    }

    public function resourceMetadataUrl(): string
    {
        return $this->issuer().'/.well-known/oauth-protected-resource/mcp';
    }

    public function resourceMatches(?string $resource): bool
    {
        return $resource !== null && rtrim($resource, '/') === $this->resource();
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return array_keys(config('mcp.scopes', []));
    }

    /** @return array<string, mixed> */
    public function protectedResource(): array
    {
        return [
            'resource' => $this->resource(),
            'resource_name' => '10xScale ERP MCP',
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => $this->scopes(),
            'bearer_methods_supported' => ['header'],
        ];
    }

    /** @return array<string, mixed> */
    public function authorizationServer(): array
    {
        $issuer = $this->issuer();
        $metadata = [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => $this->scopes(),
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => $issuer.'/settings/ai-mcp',
        ];
        if (config('mcp.oauth.dynamic_client_registration', true)) {
            $metadata['registration_endpoint'] = $issuer.'/oauth/register';
        }

        return $metadata;
    }
}
