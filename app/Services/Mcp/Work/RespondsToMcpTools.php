<?php

namespace App\Services\Mcp\Work;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Throwable;

/**
 * W6 Work Center tools: the existing MCP envelope ({ok, data} | {ok:false,
 * error:{code, message, details}}) and error contract. Canonical actions
 * report a stale `expected_version` and an ended item as validation errors on
 * `version` / `status`; MCP surfaces them as explicit conflicts so an agent
 * never overwrites newer human work.
 */
trait RespondsToMcpTools
{
    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback, string $notFoundCode = 'MCP_ENTITY_NOT_FOUND'): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (ModelNotFoundException) {
            // Same answer whether the record does not exist or is outside the connection's visibility.
            return $this->error($notFoundCode, 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['version'])) {
                return $this->error('MCP_STALE_VERSION', 'The record changed since it was read. Read it again, then retry with its current version.');
            }
            if (isset($errors['status']) && count($errors) === 1) {
                return $this->error('MCP_INVALID_STATE', mb_substr((string) $errors['status'][0], 0, 200));
            }

            return $this->error('MCP_VALIDATION_FAILED', 'The candidate data is invalid.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage() === 'idempotency_key_payload_mismatch'
                ? $this->error('MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.')
                : $this->error('MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.');
        } catch (Throwable $exception) {
            Log::warning('MCP work center tool failed.', [
                'tool' => $tool,
                'connection_id' => $this->context->connection()?->getKey(),
                'exception_type' => $exception::class,
            ]);

            return $this->error('MCP_TOOL_INTERNAL_ERROR', 'The tool could not complete the request.');
        }
    }

    /** @param array<string, mixed> $data */
    private function success(array $data): CallToolResult
    {
        $payload = ['ok' => true, 'data' => $data];

        return new CallToolResult([new TextContent($payload)], structuredContent: $payload);
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, array $details = []): CallToolResult
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        $payload = ['ok' => false, 'error' => $error];

        return new CallToolResult([new TextContent($payload)], isError: true, structuredContent: $payload);
    }

    /** @return array<string, list<string>> */
    private function sanitizedErrors(ValidationException $exception): array
    {
        return collect($exception->errors())->take(20)
            ->map(fn ($messages) => array_values(array_map(fn ($message) => mb_substr((string) $message, 0, 200), array_slice((array) $messages, 0, 3))))
            ->all();
    }

    /** Read audit, with the MCP connection attribution added by AuditLogger. @param array<string, mixed> $metadata */
    private function auditRead(string $event, array $metadata): void
    {
        $this->audit->record(event: $event, actor: $this->context->user(), organization: $this->context->organization(), newValues: $metadata);
    }

    /** @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $page @return array<string, mixed> */
    private function pagination($page): array
    {
        return [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'has_more' => $page->hasMorePages(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ];
    }
}
