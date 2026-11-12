<?php
namespace App\Services\Sandbox;
use App\Models\Sandbox;
use App\Services\ActiveTenantContext;
use Illuminate\Http\Request;
class SandboxContext
{
    public const SESSION_KEY = 'sandbox_context';
    private ?Sandbox $sandbox = null;
    private bool $selected = false;
    public function resolve(Request $request, ActiveTenantContext $tenant): void
    {
        $this->sandbox = null;
        $selection = $request->session()->get(self::SESSION_KEY);
        $this->selected = is_array($selection);
        $org = $tenant->organization();
        $user = $request->user();
        if (!$this->selected || !$org || !$user) return;
        if (($selection['user_id'] ?? null) !== $user->id || ($selection['organization_id'] ?? null) !== $org->id) return;
        if (!(($org->settings ?? [])['sandbox_enabled'] ?? false)) return;
        if (!$user->hasPermission($org, 'sandbox.access') || !$user->hasPermission($org, 'sandbox.switch')) return;
        $this->sandbox = Sandbox::query()->where('organization_id', $org->id)->whereKey($selection['id'] ?? null)
            ->where('generation', $selection['generation'] ?? null)->where('status', 'active')->first();
    }
    public function id(): ?int { return $this->sandbox?->id; }
    public function sandbox(): ?Sandbox { return $this->sandbox; }
    public function isSandbox(): bool { return $this->sandbox !== null; }
    /** A record owned by another environment (NULL = Production) is invisible: 404. */
    public function assertSameEnvironment(?int $sandboxId): void { abort_unless((string) $sandboxId === (string) $this->id(), 404); }
    // Invalidated selections remain blocked until an explicit exit; never fall through to production writes.
    public function isRestricted(): bool { return $this->selected; }
}
