<?php

namespace App\Services\Mcp\Sales;

use App\Actions\Quotations\SendQuotationEmailAction;
use App\Enums\QuotationStatus;
use App\Mail\QuotationDocumentMail;
use App\Models\DocumentEmailDelivery;
use App\Models\Quotation;
use App\Services\DocumentPdfService;
use App\Services\DocumentStampRenderer;
use App\Services\Documents\DocumentEmailRecipientService;
use App\Services\Mcp\McpExecutionContext;
use App\Services\OrganizationOutboundMailService;
use App\Services\QuotationDocumentRenderer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Quotation PDF + email for MCP, built only from the ERP's own pieces:
 * DocumentPdfService (same PDF as the web preview/download, BROUILLON on
 * drafts), DocumentStampRenderer (an existing stamp apposition), the
 * DocumentEmailRecipientService envelope rules, QuotationDocumentMail and
 * SendQuotationEmailAction for the actual send.
 */
final class McpQuotationDocumentService
{
    public const EMAIL_SCOPE = 'quotations.email';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly QuotationReadService $quotations,
        private readonly DocumentPdfService $pdf,
        private readonly DocumentStampRenderer $stamps,
        private readonly QuotationDocumentRenderer $renderer,
        private readonly DocumentEmailRecipientService $recipients,
        private readonly OrganizationOutboundMailService $mail,
        private readonly SendQuotationEmailAction $sendAction,
    ) {}

    /* ------------------------------------------------------------------
     |  Document
     * ---------------------------------------------------------------- */

    /** @return array<string, mixed> */
    public function document(int $quotationId, bool $includeStamp): array
    {
        $quotation = $this->quotations->find($this->context->organization(), $quotationId);
        $document = $this->render($quotation);
        $stamp = $this->stampStatus($quotation, $includeStamp);
        $ttl = (int) config('mcp.documents.link_ttl_seconds', 600);
        $expiresAt = now()->addSeconds($ttl);

        return [
            'quotation_id' => $quotation->getKey(),
            'quotation_number' => $quotation->quotation_number,
            'status' => $quotation->status->value,
            'is_draft_watermarked' => $quotation->status === QuotationStatus::Draft,
            'filename' => $document['filename'],
            'mime_type' => $document['mime'],
            'size_bytes' => strlen($document['bytes']),
            'sha256' => hash('sha256', $document['bytes']),
            'generated_at' => now()->toIso8601String(),
            'company_stamp_applied' => $stamp['applied'],
            'warnings' => $stamp['warnings'],
            // Short-lived signed link bound to this quotation and MCP connection;
            // re-checked against the connection, scope and permission on use.
            'download_url' => URL::temporarySignedRoute('mcp.documents.quotation', $expiresAt, [
                'quotation' => $quotation->getKey(),
                'connection' => $this->context->connectionOrFail()->getKey(),
            ]),
            'download_expires_at' => $expiresAt->toIso8601String(),
            'version' => $this->quotations->version($quotation),
        ];
    }

    /** @return array{bytes: string, filename: string, mime: string} */
    public function render(Quotation $quotation): array
    {
        try {
            return $this->pdf->quotation($quotation);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw new InvalidArgumentException('document_unavailable');
        }
    }

    /* ------------------------------------------------------------------
     |  Email
     * ---------------------------------------------------------------- */

    /**
     * Recipients come either as explicit `to`/`cc`/`bcc` lists or, for older
     * clients, as `recipient_mode` + `recipient_email` (which only define
     * `to`). Mixing `to` with the legacy fields is rejected as ambiguous.
     *
     * @param  array{to?: mixed, cc?: mixed, bcc?: mixed, recipient_mode?: ?string, recipient_email?: ?string}  $recipients
     * @return array<string, mixed>
     */
    public function previewEmail(int $quotationId, array $recipients, ?string $subject, ?string $message, bool $includeStamp): array
    {
        $legacyMode = $recipients['recipient_mode'] ?? null;
        $legacyEmail = $recipients['recipient_email'] ?? null;
        if (($recipients['to'] ?? null) !== null) {
            if ($legacyMode !== null || $legacyEmail !== null) throw new InvalidArgumentException('ambiguous_recipients');
            $mode = 'explicit';
        } else {
            $mode = $legacyMode ?? ($legacyEmail !== null ? 'custom' : 'customer');
            if (! in_array($mode, ['customer', 'custom'], true)) throw new InvalidArgumentException('invalid_recipient_mode');
            if ($mode === 'customer' && $legacyEmail !== null) throw new InvalidArgumentException('ambiguous_recipients');
        }
        foreach (['to', 'cc', 'bcc'] as $bucket) {
            if (($recipients[$bucket] ?? null) !== null && ! is_array($recipients[$bucket])) throw new InvalidArgumentException('invalid_recipients');
        }
        if ($subject !== null && mb_strlen($subject) > 255) throw new InvalidArgumentException('subject_too_long');
        if ($message !== null && mb_strlen($message) > 5000) throw new InvalidArgumentException('message_too_long');
        $subject = $subject !== null && trim($subject) !== '' ? trim($subject) : null;
        $message = $message !== null && trim($message) !== '' ? $message : null;

        $quotation = $this->quotations->find($this->context->organization(), $quotationId);
        $issues = [];

        // Same status rules as SendQuotationEmailAction + the quotation `email` policy.
        if ($quotation->status === QuotationStatus::Draft) {
            $issues[] = $this->issue('quotation', 'quotation_not_issued', 'Only an issued quotation can be sent. Issue it first.');
        } elseif ($quotation->status === QuotationStatus::Superseded) {
            $issues[] = $this->issue('quotation', 'quotation_superseded', 'A superseded revision cannot be sent; send the current version.');
        }
        if (! $this->mail->isConfigured($quotation->organization)) {
            $issues[] = $this->issue('mail', 'mail_not_configured', 'Outbound email is not configured for this organization (Settings → email).');
        }

        $to = match ($mode) {
            'explicit' => $recipients['to'],
            'customer' => filled($quotation->customer_email) ? [['email' => $quotation->customer_email, 'name' => $quotation->customer_name]] : [],
            'custom' => filled($legacyEmail) ? [['email' => $legacyEmail]] : [],
        };
        if ($to === []) {
            $issues[] = match ($mode) {
                'customer' => $this->issue('to', 'customer_email_missing', 'The quotation has no customer email. Pass the recipients in "to".'),
                'custom' => $this->issue('to', 'recipient_email_required', 'recipient_email is required with recipient_mode "custom".'),
                default => $this->issue('to', 'recipient_required', 'At least one "to" recipient is required.'),
            };
        }
        [$envelope, $recipientIssues, $warnings] = $this->envelope($quotation, ['to' => $to, 'cc' => $recipients['cc'] ?? [], 'bcc' => $recipients['bcc'] ?? []], $to !== []);
        $issues = [...$issues, ...$recipientIssues];

        $effectiveSubject = $subject ?? __('documents.email.quotation_subject', ['number' => $quotation->quotation_number], config('documents.locale'));
        $stamp = $this->stampStatus($quotation, $includeStamp);
        $attachment = null;
        $bodyPreview = null;
        if ($issues === []) {
            $document = $this->render($quotation);
            $attachment = ['filename' => $document['filename'], 'mime_type' => $document['mime'], 'size_bytes' => strlen($document['bytes'])];
            // Render the real mail template (no PDF bytes needed for the body).
            $html = (new QuotationDocumentMail($this->renderer->payload($quotation), '', $document['filename'], $effectiveSubject, $message))->render();
            $bodyPreview = mb_substr(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))) ?? ''), 0, 4000);
        }

        $fingerprint = null;
        $expiresAt = null;
        if ($issues === []) {
            $fingerprint = bin2hex(random_bytes(32));
            $expires = now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300));
            Cache::put($this->emailKey($fingerprint), [
                'organization_id' => (int) $this->context->organization()->getKey(),
                'connection_id' => (string) $this->context->connectionOrFail()->getKey(),
                'user_id' => (int) $this->context->user()->getKey(),
                'quotation_id' => $quotationId,
                'version' => $this->quotations->version($quotation),
                'required_scopes' => [self::EMAIL_SCOPE],
                // Exactly what will be sent (the whole To/CC/BCC envelope);
                // execution accepts nothing else.
                'envelope' => $envelope,
                'subject' => $subject,
                'message' => $message,
                'include_company_stamp' => $includeStamp,
                'company_stamp_applied' => $stamp['applied'],
            ], $expires);
            $expiresAt = $expires->toIso8601String();
        }

        return [
            'quotation' => [
                'id' => $quotation->getKey(), 'quotation_number' => $quotation->quotation_number,
                'status' => $quotation->status->value, 'customer' => $quotation->customer_name,
                'total_incl_tax' => (string) $quotation->total_incl_tax, 'currency_code' => $quotation->currency_code,
            ],
            'recipient_source' => $mode,
            'to' => $envelope['to'] ?? [],
            'cc' => $envelope['cc'] ?? [],
            'bcc' => $envelope['bcc'] ?? [],
            'subject' => $effectiveSubject,
            'message' => $message,
            'body_preview' => $bodyPreview,
            'attachment' => $attachment,
            'company_stamp_applied' => $stamp['applied'],
            'warnings' => [...$warnings, ...$stamp['warnings']],
            'delivery' => 'synchronous',
            'valid' => $issues === [],
            'issues' => $issues,
            'required_scopes' => [self::EMAIL_SCOPE],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'Nothing was sent. send_quotation_email sends ONE email to exactly these To/CC/BCC recipients with this subject and message.',
        ];
    }

    /**
     * Validates each recipient on its own through the ERP envelope rules (so
     * every bad address gets its own issue), then builds the whole envelope
     * with the same service the web dialog uses: lowercased emails, first
     * occurrence kept across to → cc → bcc, at least one `to`, the ERP
     * recipient limit. Only email + name are passed: MCP never selects or
     * saves contacts.
     *
     * @param  array{to: mixed, cc: mixed, bcc: mixed}  $groups
     * @return array{0: ?array{to: list<array{email: string, name: ?string}>, cc: list<array{email: string, name: ?string}>, bcc: list<array{email: string, name: ?string}>}, 1: list<array{path: string, code: string, message: string}>, 2: list<array{code: string, message: string}>}
     */
    private function envelope(Quotation $quotation, array $groups, bool $build): array
    {
        $issues = [];
        $warnings = [];
        $payload = ['to' => [], 'cc' => [], 'bcc' => []];
        $seen = [];

        foreach (['to', 'cc', 'bcc'] as $bucket) {
            foreach (array_values(is_array($groups[$bucket]) ? $groups[$bucket] : []) as $index => $entry) {
                $path = "{$bucket}[{$index}]";
                $recipient = [
                    'email' => is_array($entry) && is_string($entry['email'] ?? null) ? trim($entry['email']) : null,
                    'name' => is_array($entry) && is_string($entry['name'] ?? null) ? trim($entry['name']) : null,
                ];
                try {
                    $normalized = $this->recipients->envelope($this->context->user(), $quotation->organization, ['to' => [$recipient]])['to'][0];
                } catch (ValidationException $exception) {
                    $issues[] = $this->issue($path, 'invalid_recipient', (string) (collect($exception->errors())->flatten()->first() ?? 'Adresse email invalide.'));

                    continue;
                }
                if (isset($seen[$normalized['email']])) {
                    $warnings[] = ['code' => 'duplicate_recipient_removed', 'message' => "{$normalized['email']} ({$path}) is already listed at {$seen[$normalized['email']]}; it receives the email once."];
                } else {
                    $seen[$normalized['email']] = $path;
                }
                $payload[$bucket][] = $recipient;
            }
        }

        if (! $build || $issues !== []) {
            return [null, $issues, $warnings];
        }

        try {
            $envelope = $this->recipients->envelope($this->context->user(), $quotation->organization, $payload);
        } catch (ValidationException $exception) {
            return [null, [...$issues, $this->issue('to', 'too_many_recipients', (string) (collect($exception->errors())->flatten()->first() ?? 'Too many recipients.'))], $warnings];
        }
        $strip = fn (array $list) => array_map(fn (array $r) => ['email' => $r['email'], 'name' => $r['name']], $list);

        return [['to' => $strip($envelope['to']), 'cc' => $strip($envelope['cc']), 'bcc' => $strip($envelope['bcc'])], $issues, $warnings];
    }

    /**
     * Send through SendQuotationEmailAction. NOT inside a DB transaction: the
     * ERP records its own sent/failed delivery and audit, which must survive a
     * transport failure. The fingerprint is claimed atomically first, so it can
     * trigger at most one send.
     *
     * @return array<string, mixed>
     */
    public function send(int $quotationId, string $fingerprint): array
    {
        $stored = preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? Cache::get($this->emailKey($fingerprint)) : null;
        if (! is_array($stored)
            || (int) ($stored['organization_id'] ?? 0) !== (int) $this->context->organization()->getKey()
            || ! hash_equals((string) ($stored['connection_id'] ?? ''), (string) $this->context->connectionOrFail()->getKey())
            || (int) ($stored['user_id'] ?? 0) !== (int) $this->context->user()->getKey()
            || (int) ($stored['quotation_id'] ?? 0) !== $quotationId
            || ($stored['required_scopes'] ?? null) !== [self::EMAIL_SCOPE]
            || ! is_array($stored['envelope'] ?? null) || ($stored['envelope']['to'] ?? []) === []) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }

        $quotation = $this->quotations->find($this->context->organization(), $quotationId);
        if (! hash_equals((string) $stored['version'], $this->quotations->version($quotation))
            || $this->stampStatus($quotation, (bool) $stored['include_company_stamp'])['applied'] !== $stored['company_stamp_applied']) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        // Atomic single-use claim (add() is atomic in the cache store).
        if (! Cache::add($this->claimKey($fingerprint), 1, now()->addDay())) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        Cache::forget($this->emailKey($fingerprint));

        $startedAt = now();
        $result = [
            'quotation_id' => $quotation->getKey(),
            'quotation_number' => $quotation->quotation_number,
            'to' => $stored['envelope']['to'],
            'cc' => $stored['envelope']['cc'],
            'bcc' => $stored['envelope']['bcc'],
            'subject' => $stored['subject'] ?? __('documents.email.quotation_subject', ['number' => $quotation->quotation_number], config('documents.locale')),
            'company_stamp_applied' => $stored['company_stamp_applied'],
        ];

        try {
            $this->sendAction->execute(
                $this->context->user(),
                $quotation,
                // One email to the whole frozen envelope (one ERP delivery record).
                ['to' => $stored['envelope']['to'], 'cc' => $stored['envelope']['cc'], 'bcc' => $stored['envelope']['bcc']],
                $stored['subject'],
                $stored['message'],
            );
        } catch (ValidationException $exception) {
            return [...$result, 'sent' => false, 'status' => 'rejected', 'error' => mb_substr((string) (collect($exception->errors())->flatten()->first() ?? 'Rejected.'), 0, 300)];
        } catch (Throwable $exception) {
            // The transport may or may not have accepted the message: never auto-retry.
            return [...$result, 'sent' => false, 'status' => 'failed', 'error' => 'The email could not be sent; the ERP recorded the failed delivery. Preview again before any new attempt.', 'delivery' => $this->delivery($quotation, $startedAt)];
        }

        return [
            ...$result,
            'sent' => true,
            // Synchronous SMTP: accepted by the transport, not a delivery receipt.
            'status' => 'sent',
            'queued' => false,
            'delivery' => $this->delivery($quotation, $startedAt),
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /** The ERP's own delivery record for this send. @return array{id: int, status: string}|null */
    private function delivery(Quotation $quotation, CarbonInterface $since): ?array
    {
        $delivery = DocumentEmailDelivery::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('document_type', Quotation::class)->where('document_id', $quotation->getKey())
            ->where('sender_user_id', $this->context->user()->getKey())
            ->where('created_at', '>=', $since->copy()->subSecond())
            ->latest('id')->first(['id', 'status']);

        return $delivery ? ['id' => (int) $delivery->getKey(), 'status' => (string) $delivery->status] : null;
    }

    /**
     * The ERP renders a stamp only from an existing apposition (applied through
     * the separate, persistent "Apposer le cachet" action). There is no
     * render-time toggle, so the flag can only be reported against reality.
     *
     * @return array{applied: bool, warnings: list<array{code: string, message: string}>}
     */
    private function stampStatus(Quotation $quotation, bool $requested): array
    {
        $applied = $this->stamps->isRenderable($quotation->stampApposition ?? $quotation->stampApposition()->first());
        $warnings = [];
        if ($requested && ! $applied) {
            $warnings[] = ['code' => 'stamp_not_apposed', 'message' => 'No company stamp is apposed on this quotation. The ERP applies the stamp as an explicit, persistent action on issued quotations (Apposer le cachet); the document was generated without it.'];
        }
        if (! $requested && $applied) {
            $warnings[] = ['code' => 'stamp_already_apposed', 'message' => 'This quotation already carries an apposed company stamp; the ERP document always includes it.'];
        }

        return ['applied' => $applied, 'warnings' => $warnings];
    }

    private function emailKey(string $fingerprint): string
    {
        return 'mcp:quotation-email-preview:'.hash('sha256', $fingerprint);
    }

    private function claimKey(string $fingerprint): string
    {
        return 'mcp:quotation-email-claimed:'.hash('sha256', $fingerprint);
    }

    /** @return array{path: string, code: string, message: string} */
    private function issue(string $path, string $code, string $message): array
    {
        return ['path' => $path, 'code' => $code, 'message' => $message];
    }
}
