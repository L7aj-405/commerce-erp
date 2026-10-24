<?php

namespace App\Models;

use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

#[Fillable(['name', 'settings'])]
class Organization extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * The canonical organization business timezone (IANA), stored in
     * settings['timezone']. UTC until explicitly configured; never inferred.
     */
    public function businessTimezone(): string
    {
        $timezone = data_get($this->settings, 'timezone');

        return BusinessTime::isValidTimezone($timezone) ? $timezone : BusinessTime::DEFAULT_TIMEZONE;
    }

    /** Whether an administrator has explicitly configured a valid business timezone. */
    public function hasConfiguredTimezone(): bool
    {
        return BusinessTime::isValidTimezone(data_get($this->settings, 'timezone'));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function backupSetting(): HasOne
    {
        return $this->hasOne(OrganizationBackupSetting::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(OrganizationBackup::class);
    }

    public function cloudBackupConnections(): HasMany
    {
        return $this->hasMany(OrganizationCloudBackupConnection::class);
    }

    public function mcpConnections(): HasMany
    {
        return $this->hasMany(McpConnection::class);
    }

    public function allowsMcpConnections(): bool
    {
        return (bool) data_get($this->settings, 'allow_mcp_connections', false);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productImports(): HasMany
    {
        return $this->hasMany(ProductImport::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function unitsOfMeasure(): HasMany
    {
        return $this->hasMany(UnitOfMeasure::class);
    }

    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function inventoryReservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function customerReturns(): HasMany
    {
        return $this->hasMany(CustomerReturn::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function financeObligations(): HasMany
    {
        return $this->hasMany(FinanceObligation::class);
    }

    public function financeOutgoingPayments(): HasMany
    {
        return $this->hasMany(FinanceOutgoingPayment::class);
    }

    public function financeRecurringObligationTemplates(): HasMany
    {
        return $this->hasMany(FinanceRecurringObligationTemplate::class);
    }

    public function financeSupplierInstallmentPlans(): HasMany { return $this->hasMany(FinanceSupplierInstallmentPlan::class); }

    public function financialAccountOpeningPositions(): HasMany { return $this->hasMany(FinancialAccountOpeningPosition::class); }

    public function financeExpectedCollections(): HasMany { return $this->hasMany(FinanceExpectedCollection::class); }
    public function financeMonthReviews(): HasMany { return $this->hasMany(FinanceMonthReview::class); }
    public function financePaymentInstruments(): HasMany { return $this->hasMany(FinancePaymentInstrument::class); }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(OrganizationContact::class);
    }

    public function documentEmailDeliveries(): HasMany
    {
        return $this->hasMany(DocumentEmailDelivery::class);
    }

    public function mailSetting(): HasOne
    {
        return $this->hasOne(OrganizationMailSetting::class);
    }

    public function securitySetting(): HasOne
    {
        return $this->hasOne(OrganizationSecuritySetting::class);
    }

    public function requiresTwoFactor(): bool
    {
        return (bool) $this->securitySetting?->require_2fa;
    }

    /** Sprint 1.1 §7 — see EnsureTwoFactorPolicy and User::hasPrivilegedRoleIn(). */
    public function requiresTwoFactorForPrivilegedRoles(): bool
    {
        return (bool) $this->securitySetting?->require_2fa_for_privileged_roles;
    }

    public function documentStamps(): HasMany
    {
        return $this->hasMany(OrganizationDocumentStamp::class);
    }

    public function activeDocumentStamp(): HasOne
    {
        return $this->hasOne(OrganizationDocumentStamp::class)->where('active', true);
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query->whereHas(
                'memberships',
                fn ($query) => $query
                    ->where('user_id', Auth::id())
                    ->where('status', 'active'),
            ));
    }
}
