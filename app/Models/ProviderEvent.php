<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    protected $fillable = [
        'provider_event_id',
        'policy_request_id',
        'event_type',
        'event_data',
        'processing_status',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'event_data' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
