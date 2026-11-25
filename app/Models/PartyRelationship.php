<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyRelationship extends Model
{
    public const TYPES = ['employee', 'interlocutor', 'representative', 'owner'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'active' => 'boolean'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function relatedParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'related_party_id');
    }
}
