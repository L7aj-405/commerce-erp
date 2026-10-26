<?php

namespace App\Console\Commands;

use App\Actions\Settings\UpdateOrganizationTimezoneAction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PILOT-1.1 operator bootstrap of a closed-pilot organization owner, used
 * while public registration is disabled (config auth.public_registration).
 *
 * Creates a NEW user (never touches an existing account) through the
 * canonical OrganizationCreator (Owner role, permissions, membership,
 * privileged-role 2FA policy, organization.created audit) and optionally sets
 * the business timezone through UpdateOrganizationTimezoneAction. No
 * password is ever chosen or printed: the account gets an unusable random
 * password and the command prints a one-time password-setup link (the
 * standard reset token, valid auth.passwords.users.expire minutes) for the
 * operator to hand to the owner. The email is marked verified because the
 * operator vouches for it; no email is sent.
 */
class BootstrapOwnerCommand extends Command
{
    protected $signature = 'erp:bootstrap-owner
        {email : Email address of the new owner account}
        {--name= : Owner display name}
        {--organization= : Organization name}
        {--timezone= : Optional IANA business timezone, e.g. Africa/Casablanca}';

    protected $description = 'Create a pilot organization and its owner account, then print a one-time password-setup link';

    public function handle(OrganizationCreator $organizations, UpdateOrganizationTimezoneAction $timezones, AuditLogger $audit): int
    {
        $input = [
            'email' => mb_strtolower(trim((string) $this->argument('email'))),
            'name' => trim((string) $this->option('name')),
            'organization' => trim((string) $this->option('organization')),
            'timezone' => $this->option('timezone') === null ? null : trim((string) $this->option('timezone')),
        ];
        try {
            Validator::make($input, [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'organization' => ['required', 'string', 'max:255'],
                'timezone' => ['nullable', 'timezone:all'],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error("{$field}: {$messages[0]}");
            }

            return self::INVALID;
        }

        if (User::query()->where('email', $input['email'])->exists()) {
            $this->error('An account with this email already exists; it is never modified by this command. Invite it from the organization instead.');

            return self::FAILURE;
        }

        [$user, $organization] = DB::transaction(function () use ($input, $organizations, $timezones, $audit) {
            $user = new User;
            $user->name = $input['name'];
            $user->email = $input['email'];
            $user->password = Str::random(64); // unusable until the owner sets a password through the link
            $user->email_verified_at = now();
            $user->save();

            $organization = $organizations->create($user, $input['organization']);
            if ($input['timezone'] !== null) {
                $organization = $timezones->execute($user, $organization, $input['timezone']);
            }
            $audit->record('organization.owner_bootstrapped', null, $organization, auditable: $user,
                newValues: ['user_id' => (int) $user->getKey(), 'channel' => 'artisan']);

            return [$user, $organization];
        });

        $token = Password::broker()->createToken($user);
        $link = url(route('password.reset', ['token' => $token, 'email' => $user->email], false));
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.expire', 60);

        $this->info("Organization #{$organization->getKey()} \"{$organization->name}\" created; owner #{$user->getKey()} <{$user->email}>.");
        $this->line('Business timezone: '.$organization->businessTimezone().($organization->hasConfiguredTimezone() ? '' : ' (default — set it in Settings → Company)'));
        $this->line("One-time password-setup link (valid {$minutes} minutes; share it privately with the owner only):");
        $this->line($link);
        $this->line('If it expires, the owner can use "Mot de passe oublié" once platform SMTP is configured.');

        return self::SUCCESS;
    }
}
