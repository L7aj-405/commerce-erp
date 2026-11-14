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
    /** Phase 10: generation captured when the request resolved (the write fence compares against it). */
    private ?int $generation = null;
    /** Phase 10: the selection points at this organization's sandbox, but a reset advanced its generation. */
    private bool $stale = false;
    private ?array $selection = null;
    public function resolve(Request $request, ActiveTenantContext $tenant): void
    {
        $this->sandbox = null;
        $this->generation = null;
        $this->stale = false;
        $this->selection = null;
        $selection = $request->session()->get(self::SESSION_KEY);
        $this->selected = is_array($selection);
        $this->selection = $this->selected ? $selection : null;
        $org = $tenant->organization();
        $user = $request->user();
        if (!$this->selected || !$org || !$user) return;
        if (($selection['user_id'] ?? null) !== $user->id || ($selection['organization_id'] ?? null) !== $org->id) return;
        if (!(($org->settings ?? [])['sandbox_enabled'] ?? false)) return;
        if (!$user->hasPermission($org, 'sandbox.access') || !$user->hasPermission($org, 'sandbox.switch')) return;
        $current = Sandbox::query()->where('organization_id', $org->id)->whereKey($selection['id'] ?? null)->where('status', 'active')->first();
        if ($current && (int) $current->generation === (int) ($selection['generation'] ?? 0)) {
            $this->sandbox = $current;
            $this->generation = (int) $current->generation;
        } elseif ($current && (int) $current->generation > (int) ($selection['generation'] ?? 0)) {
            // Never silently continue on the new generation: the session must re-enter.
            $this->stale = true;
        }
    }
    public function id(): ?int { return $this->sandbox?->id; }
    public function generation(): ?int { return $this->generation; }
    public function isStale(): bool { return $this->stale; }
    /** @return array{id?:int,organization_id?:int,user_id?:int,generation?:int}|null the raw session selection */
    public function selection(): ?array { return $this->selection; }
    public function sandbox(): ?Sandbox { return $this->sandbox; }
    public function isSandbox(): bool { return $this->sandbox !== null; }
    /** A record owned by another environment (NULL = Production) is invisible: 404. */
    public function assertSameEnvironment(?int $sandboxId): void { abort_unless((string) $sandboxId === (string) $this->id(), 404); }
    // Invalidated selections remain blocked until an explicit exit; never fall through to production writes.
    public function isRestricted(): bool { return $this->selected; }
}
