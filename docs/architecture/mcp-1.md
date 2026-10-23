# MCP-1 connection and security foundation

The MCP endpoint uses the official PHP SDK (`mcp/sdk` 0.9.x), maintained by
the MCP project in collaboration with the PHP Foundation and Symfony. The
transport is Streamable HTTP at `/mcp`; the application does not implement the
MCP wire protocol itself.

Laravel authenticates a high-entropy opaque bearer credential before handing
the PSR-7 request to the SDK. The credential identifies exactly one immutable
connection/organization pair. Browser session tenant state and tool input are
never consulted for MCP tenancy. The stored token is only a SHA-256 digest;
the raw value is returned once on issue or rotation.

The current registry is intentionally empty. MCP-2 may register product tools
against application Actions without changing the connection, tenant, audit, or
authorization layers. OAuth 2.1 can later replace bearer authentication at the
edge while producing the same `McpExecutionContext`.

Effective authorization requires both the current ERP permission held by the
owning active organization member and the connection scope. Disabling the
organization policy, revoking/expiring a connection, removing membership, or
removing an ERP permission takes effect on the next request.

The SDK's DNS-rebinding and CORS middleware remain enabled. Production must
configure `MCP_ALLOWED_HOSTS` for the public MCP hostname and may configure
explicit `MCP_ALLOWED_ORIGINS` when a browser-based client is required.
