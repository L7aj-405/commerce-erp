<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooCommerceSyncRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    /* Phases (live position of a run; `status` stays the lifecycle). */
    public const PHASE_STARTING = 'starting';

    public const PHASE_LOADING_SETTINGS = 'loading_settings';

    public const PHASE_LOADING_CATEGORIES = 'loading_categories';

    public const PHASE_LOADING_PRODUCTS = 'loading_products';

    public const PHASE_LOADING_VARIATIONS = 'loading_variations';

    public const PHASE_IMPORTING_PRODUCTS = 'importing_products';

    /** Waiting out a 429 (Retry-After / backoff) — still running, not failed. */
    public const PHASE_RATE_LIMITED = 'rate_limited';

    /** Waiting out a 5xx / connection error backoff — still running. */
    public const PHASE_RETRYING = 'retrying';

    public const PHASE_COMPLETED = 'completed';

    public const PHASE_FAILED = 'failed';

    /** Most recent API diagnostics kept on a run. */
    public const MAX_DIAGNOSTICS = 50;

    protected $table = 'woocommerce_sync_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'errors' => 'array',
            'progress' => 'array',
            'api_diagnostics' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WooCommerceIntegration::class, 'woocommerce_integration_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
