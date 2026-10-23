<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;

/**
 * Manages safe state transitions for policy requests.
 * Centralizes all transition validation to prevent invalid state changes.
 */
class PolicyStateMachine
{
    /**
     * Allowed transitions: current_state => [allowed_next_states].
     */
    private const TRANSITIONS = [
        'RECEIVED'               => ['VALIDATED', 'FAILED'],
        'VALIDATED'              => ['SUBMITTED', 'FAILED'],
        'SUBMITTED'              => ['ISSUED', 'FAILED', 'PENDING_CONFIRMATION'],
        'PENDING_CONFIRMATION'   => ['ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'NEEDS_RECONCILIATION'   => ['ISSUED', 'FAILED', 'SUBMITTED'],
        // Terminal states — no further transitions
        'ISSUED'                 => [],
        'FAILED'                 => ['SUBMITTED'], // allow retry from FAILED
    ];

    /**
     * Check if a transition is allowed.
     */
    public function canTransition(string $currentState, string $newState): bool
    {
        if (!isset(self::TRANSITIONS[$currentState])) {
            return false;
        }
        return in_array($newState, self::TRANSITIONS[$currentState], true);
    }

    /**
     * Transition a policy request to a new state.
     * Records the transition in state history.
     *
     * @throws \InvalidArgumentException if the transition is invalid
     */
    public function transition(
        PolicyRequest $policyRequest,
        string $newState,
        string $reason = '',
        ?int $relatedAttemptId = null,
        ?int $relatedEventId = null
    ): PolicyRequest {
        $currentState = $policyRequest->status;

        if (!$this->canTransition($currentState, $newState)) {
            throw new \InvalidArgumentException(
                "Invalid state transition from '{$currentState}' to '{$newState}'"
            );
        }

        $policyRequest->status = $newState;
        $policyRequest->save();

        PolicyStateHistory::create([
            'policy_request_id' => $policyRequest->id,
            'previous_state'    => $currentState,
            'new_state'         => $newState,
            'reason'            => $reason,
            'related_attempt_id' => $relatedAttemptId,
            'related_event_id'  => $relatedEventId,
        ]);

        return $policyRequest;
    }

    /**
     * Get allowed next states for a given current state.
     */
    public function allowedTransitions(string $currentState): array
    {
        return self::TRANSITIONS[$currentState] ?? [];
    }
}
