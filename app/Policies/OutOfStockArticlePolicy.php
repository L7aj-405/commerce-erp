<?php

namespace App\Policies;

use App\Models\OutOfStockArticle;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;

/**
 * Reuses the existing procurement permissions (§18 of the brief): the same
 * people who source supplier special orders are the ones who source or create
 * a genuinely new article.
 */
class OutOfStockArticlePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'procurement.view');
    }

    public function view(User $user, OutOfStockArticle $article): bool
    {
        return $this->allowed($user, $article->organization_id, 'procurement.view');
    }

    /** Flag a Custom line, or resolve a request into a real ProductVariant. */
    public function manage(User $user, OutOfStockArticle $article): bool
    {
        return $this->allowed($user, $article->organization_id, 'procurement.manage');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
