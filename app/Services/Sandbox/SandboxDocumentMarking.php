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
        'accepted' => 'Accepté (test)',
        'rejected' => 'Refusé',
        'converted' => 'Transformé en commande (test)',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function apply(array $payload, ?string $reference, string $status, ?string $notice = null, bool $keepStamp = false): array
    {
        $payload['sandbox'] = [
            'reference' => $reference,
            'status' => self::STATUS_LABELS[$status] ?? $status,
            'notice' => $notice,
        ];
        // The shared partial draws its own diagonal "DOCUMENT DE TEST" mark.
        $payload['watermark'] = null;
        // The company stamp may stay on a Test document (the Test mark is drawn
        // over it and stays dominant); the other Test documents drop it.
        if (! $keepStamp) {
            $payload['stamp'] = null;
        }
        if (isset($payload['title'])) {
            $payload['title'] .= ' (TEST)';
        }

        return $payload;
    }
}
