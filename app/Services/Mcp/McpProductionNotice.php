<?php

namespace App\Services\Mcp;

/**
 * U-4: MCP never has a test mode. Every write goes to PRODUCTION data, so each
 * write-preview response carries an additive `production_warning` field (the
 * existing payload keys are unchanged). The text is plain data for the AI client
 * to relay to the user.
 */
final class McpProductionNotice
{
    public const MESSAGE = 'Attention : ce connecteur MCP écrit dans les données de PRODUCTION de l\'organisation. Il n\'existe pas de mode test : appliquer cet aperçu crée ou modifie des données réelles. / Warning: this MCP connector writes to PRODUCTION data. There is no test mode: applying this preview changes real data.';

    /** True for tools that preview or prepare a write (preview_* and prepare_*). */
    public static function isWritePreview(string $tool): bool
    {
        return str_starts_with($tool, 'preview_') || str_starts_with($tool, 'prepare_');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function annotate(string $tool, array $data): array
    {
        if (! self::isWritePreview($tool) || array_key_exists('production_warning', $data)) {
            return $data;
        }

        return [...$data, 'production_warning' => self::MESSAGE];
    }
}
