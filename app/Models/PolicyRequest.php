<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    protected $fillable = [
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium_paise',
        'status',
        'policy_number',
        'provider_reference',
        'last_response',
        'attempt_count',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'trip_start' => 'date',
        'trip_end' => 'date',
        'sum_insured' => 'integer',
        'premium_paise' => 'integer',
        'attempt_count' => 'integer',
    ];

    /**
     * Get premium in rupees (from paise) as a float with 2 decimal places.
     */
    public function getPremiumAttribute(): float
    {
        return round($this->premium_paise / 100, 2);
    }

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class);
    }
}
