<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Single-use 2FA recovery codes (§E3). Codes are shown to the user in plain
 * text ONLY at the moment they are generated/regenerated — from then on only
 * their hashes are stored, exactly like a password. Using one marks it spent
 * (hash removed from the stored set) so it can never be reused; regenerating
 * discards every previous code.
 */
class RecoveryCodeService
{
    private const COUNT = 8;

    /** @return list<string> plaintext codes — display once, never persist as given */
    public function generate(): array
    {
        return array_map(
            fn () => Str::upper(Str::random(4).'-'.Str::random(4)),
            range(1, self::COUNT),
        );
    }

    /** @param list<string> $plainCodes
     * @return list<string> bcrypt hashes to persist
     */
    public function hash(array $plainCodes): array
    {
        return array_map(fn (string $code) => Hash::make($this->normalize($code)), $plainCodes);
    }

    /**
     * @param  list<string>  $hashedCodes
     * @return list<string>|null the remaining hashes with the matched one removed, or null if no code matched
     */
    public function consume(array $hashedCodes, string $submittedCode): ?array
    {
        $submittedCode = $this->normalize($submittedCode);

        foreach ($hashedCodes as $index => $hash) {
            if (Hash::check($submittedCode, $hash)) {
                unset($hashedCodes[$index]);

                return array_values($hashedCodes);
            }
        }

        return null;
    }

    private function normalize(string $code): string
    {
        return Str::upper(trim($code));
    }
}
