<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyStateHistory extends Model
{
    protected $table = 'policy_state_history';

    protected $fillable = [
        'policy_request_id',
        'previous_state',
        'new_state',
        'reason',
        'related_attempt_id',
        'related_event_id',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
