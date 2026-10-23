<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents a policy in the mock insurer's own database.
 * This is the provider-side record, separate from our application.
 */
class MockInsurerPolicy extends Model
{
    protected $fillable = [
        'idempotency_key',
        'policy_number',
        'provider_reference',
        'status',
        'request_data',
    ];

    protected $casts = [
        'request_data' => 'array',
    ];
}
