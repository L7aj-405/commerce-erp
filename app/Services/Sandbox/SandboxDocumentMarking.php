<?php

namespace App\Services\Sandbox;

/**
 * Marks a Test document render payload so the shared templates show the
 * "DOCUMENT DE TEST — NON COMPTABLE" banner and diagonal mark, the Test
 * reference, no official stamp and a title that cannot pass as an official
 * document. Only called for sandbox-owned documents; Production payloads are
 * returned untouched by the renderers.
 */
final class SandboxDocumentMarking
{
    private const STATUS_LABELS = [
        'draft' => 'Brouillon',
        'issued' => 'Émis en mode test',
        'cancelled' => 'Annulé',
        'superseded' => 'Remplacé',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function apply(array $payload, ?string $reference, string $status): array
    {
        $payload['sandbox'] = [
            'reference' => $reference,
            'status' => self::STATUS_LABELS[$status] ?? $status,
        ];
        // The shared partial draws its own diagonal "DOCUMENT DE TEST" mark.
        $payload['watermark'] = null;
        $payload['stamp'] = null;
        if (isset($payload['title'])) {
            $payload['title'] .= ' (TEST)';
        }

        return $payload;
    }
}
