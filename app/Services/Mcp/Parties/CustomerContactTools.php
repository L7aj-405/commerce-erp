<?php

namespace App\Services\Mcp\Parties;

use App\Models\OrganizationContact;
use App\Services\AuditLogger;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\Mcp\Sales\CustomerReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Throwable;

/**
 * Customer writes and organization contacts over MCP. Authorization is always
 * membership + ERP permission + MCP scope; tenant identity comes only from
 * the connection. Mutations are audited by the ERP's own customer.* and
 * contact.* events, which carry the MCP connection attribution.
 */
final class CustomerContactTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly CustomerReadService $customers,
        private readonly McpCustomerChangeService $customerChanges,
        private readonly ContactReadService $contacts,
        private readonly McpContactChangeService $contactChanges,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $customer */
    public function previewCustomerChanges(array $customer, ?int $customer_id = null): CallToolResult
    {
        return $this->execute('preview_customer_changes', function () use ($customer, $customer_id) {
            $mode = $customer_id === null ? 'create' : 'update';
            $this->context->authorize(McpCustomerChangeService::requiredScope($mode));
            $preview = $this->customerChanges->preview($this->customerChanges->normalize($mode, $customer, $customer_id));
            $this->auditRead('mcp.customer.previewed', [
                'mode' => $mode, 'customer_id' => $customer_id, 'changed_fields' => $preview['changed_fields'],
                'valid' => $preview['valid'], 'fingerprint_issued' => $preview['preview_fingerprint'] !== null,
            ]);

            return $preview;
        }, 'MCP_CUSTOMER_NOT_FOUND');
    }

    /** @param array<string, mixed> $customer */
    public function createCustomer(array $customer, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_customer', function () use ($customer, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('customers.create');
            $payload = $this->customerChanges->normalize('create', $customer);

            return $this->idempotency->execute('create_customer', $idempotency_key, $payload, function () use ($payload, $preview_fingerprint) {
                $created = $this->customerChanges->create($payload, $preview_fingerprint);

                // Stored verbatim as the idempotent replay; get_customer returns the full record.
                return ['target' => ['type' => 'customer', 'id' => $created->getKey()], 'customer' => $this->customerChanges->summary($created)];
            });
        });
    }

    /** @param array<string, mixed> $customer */
    public function updateCustomer(int $customer_id, array $customer, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_customer', function () use ($customer_id, $customer, $preview_fingerprint) {
            $this->context->authorize('customers.update');
            $payload = $this->customerChanges->normalize('update', $customer, $customer_id);

            return DB::transaction(function () use ($payload, $preview_fingerprint) {
                $updated = $this->customerChanges->update($payload, $preview_fingerprint);

                return ['target' => ['type' => 'customer', 'id' => $updated->getKey()], 'customer' => $this->customers->customer($this->context->organization(), (int) $updated->getKey())];
            });
        }, 'MCP_CUSTOMER_NOT_FOUND');
    }

    public function searchContacts(
        ?string $query = null,
        ?string $contact_type = null,
        ?bool $active = null,
        ?int $customer_id = null,
        ?int $supplier_id = null,
        int $page = 1,
        int $per_page = 25,
    ): CallToolResult {
        return $this->execute('search_contacts', function () use ($query, $contact_type, $active, $customer_id, $supplier_id, $page, $per_page) {
            $this->context->authorize('contacts.view');
            $result = $this->contacts->search($this->context->organization(), array_filter([
                'query' => $query, 'contact_type' => $contact_type, 'active' => $active,
                'customer_id' => $customer_id, 'supplier_id' => $supplier_id, 'page' => $page, 'per_page' => $per_page,
            ], fn ($value) => $value !== null));
            $this->auditRead('mcp.contacts.searched', [
                'has_query' => is_string($query) && trim($query) !== '', 'contact_type' => $contact_type, 'active' => $active,
                'customer_id' => $customer_id, 'supplier_id' => $supplier_id,
                'page' => $page, 'per_page' => $per_page, 'result_count' => count($result['items']),
            ]);

            return $result;
        });
    }

    public function getContact(int $contact_id): CallToolResult
    {
        return $this->execute('get_contact', function () use ($contact_id) {
            $this->context->authorize('contacts.view');
            $result = $this->contacts->contact($this->context->organization(), $contact_id);
            $this->auditRead('mcp.contact.viewed', ['contact_id' => $contact_id]);

            return $result;
        }, 'MCP_CONTACT_NOT_FOUND');
    }

    /** @param array<string, mixed> $contact */
    public function previewContactChanges(?string $mode = null, ?int $contact_id = null, array $contact = []): CallToolResult
    {
        return $this->execute('preview_contact_changes', function () use ($mode, $contact_id, $contact) {
            $mode ??= $contact_id === null ? 'create' : 'update';
            $this->context->authorize(McpContactChangeService::requiredScope($mode));
            $preview = $this->contactChanges->preview($this->contactChanges->normalize($mode, $contact, $contact_id));
            $this->auditRead('mcp.contact.previewed', [
                'mode' => $mode, 'contact_id' => $contact_id, 'changed_fields' => $preview['changed_fields'],
                'valid' => $preview['valid'], 'fingerprint_issued' => $preview['preview_fingerprint'] !== null,
            ]);

            return $preview;
        }, 'MCP_CONTACT_NOT_FOUND');
    }

    /** @param array<string, mixed> $contact */
    public function createContact(array $contact, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_contact', function () use ($contact, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('contacts.create');
            $payload = $this->contactChanges->normalize('create', $contact);

            return $this->idempotency->execute('create_contact', $idempotency_key, $payload, function () use ($payload, $preview_fingerprint) {
                $created = $this->contactChanges->create($payload, $preview_fingerprint);

                return ['target' => ['type' => 'contact', 'id' => $created->getKey()], 'contact' => $this->presentContact($created)];
            });
        });
    }

    /** @param array<string, mixed> $contact */
    public function updateContact(int $contact_id, array $contact, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_contact', function () use ($contact_id, $contact, $preview_fingerprint) {
            $this->context->authorize('contacts.update');
            $payload = $this->contactChanges->normalize('update', $contact, $contact_id);

            return DB::transaction(function () use ($payload, $preview_fingerprint) {
                $updated = $this->contactChanges->update($payload, $preview_fingerprint);

                return ['target' => ['type' => 'contact', 'id' => $updated->getKey()], 'contact' => $this->presentContact($updated)];
            });
        }, 'MCP_CONTACT_NOT_FOUND');
    }

    public function archiveContact(int $contact_id, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('archive_contact', function () use ($contact_id, $preview_fingerprint) {
            $this->context->authorize('contacts.archive');
            $payload = $this->contactChanges->normalize('archive', [], $contact_id);

            return DB::transaction(function () use ($payload, $preview_fingerprint) {
                ['contact' => $archived, 'replay' => $replay] = $this->contactChanges->archive($payload, $preview_fingerprint);

                return ['target' => ['type' => 'contact', 'id' => $archived->getKey()], 'contact' => $this->presentContact($archived), 'idempotent_replay' => $replay];
            });
        }, 'MCP_CONTACT_NOT_FOUND');
    }

    /** @return array<string, mixed> */
    private function presentContact(OrganizationContact $contact): array
    {
        $contact->load(['customer:id,organization_id,display_name,status', 'supplier:id,organization_id,name']);

        return [...$this->contacts->summary($contact), 'archived_at' => $contact->archived_at?->toIso8601String(), 'version' => $this->contacts->version($contact)];
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback, string $notFoundCode = 'MCP_ENTITY_NOT_FOUND'): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (ModelNotFoundException) {
            return $this->error($notFoundCode, 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The candidate data is invalid.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current data. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'forbidden_fields' => ['MCP_INVALID_ARGUMENTS', 'Unknown or disallowed fields were supplied.'],
                'no_changes' => ['MCP_INVALID_ARGUMENTS', 'No field was supplied.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP customer/contact tool failed.', [
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
        if ($details !== []) $error['details'] = $details;
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

    /** @param array<string, mixed> $metadata */
    private function auditRead(string $event, array $metadata): void
    {
        $this->audit->record(event: $event, actor: $this->context->user(), organization: $this->context->organization(), newValues: $metadata);
    }
}
