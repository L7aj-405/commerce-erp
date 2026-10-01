<?php

namespace App\Services\OrganizationBackups;

class OrganizationBackupCryptography
{
    public function signingKey(): string
    {
        return $this->configuredKey('signing_key', 'ORG_BACKUP_SIGNING_KEY');
    }

    public function encryptionPassword(): string
    {
        return bin2hex($this->configuredKey('encryption_key', 'ORG_BACKUP_ENCRYPTION_KEY'));
    }

    /** @param array<string, mixed> $manifest */
    public function sign(array $manifest): string
    {
        unset($manifest['signature']);

        return hash_hmac('sha256', $this->canonicalJson($manifest), $this->signingKey());
    }

    /** @param array<string, mixed> $manifest */
    public function verify(array $manifest): bool
    {
        $provided = $manifest['signature'] ?? null;

        return is_string($provided)
            && preg_match('/^[a-f0-9]{64}$/', $provided) === 1
            && hash_equals($provided, $this->sign($manifest));
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function configuredKey(string $configKey, string $environmentName): string
    {
        $configured = trim((string) config('organization-backups.'.$configKey, ''));
        $decoded = match (true) {
            str_starts_with($configured, 'base64:') => base64_decode(substr($configured, 7), true),
            str_starts_with($configured, 'hex:') => ctype_xdigit(substr($configured, 4)) ? hex2bin(substr($configured, 4)) : false,
            default => $configured,
        };

        if (! is_string($decoded) || strlen($decoded) < 32) {
            throw new OrganizationBackupException("La clé {$environmentName} doit contenir au moins 32 octets.");
        }

        return $decoded;
    }
}
