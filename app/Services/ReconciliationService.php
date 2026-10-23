<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\MockInsurerPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation service: checks the mock insurer's stored records
 * to resolve uncertain issuance outcomes.
 */
class ReconciliationService
{
    public function __construct(
        private MockInsurerService $mockInsurer,
        private PolicyStateMachine $stateMachine,
    ) {}

    /**
     * Reconcile a single policy request that has an uncertain outcome.
     *
     * @return array Result of the reconciliation attempt
     */
    public function reconcile(PolicyRequest $policyRequest): array
    {
        // Only reconcile requests in uncertain states
        $uncertainStates = ['PENDING_CONFIRMATION', 'NEEDS_RECONCILIATION'];
        if (!in_array($policyRequest->status, $uncertainStates, true)) {
            return [
                'reconciled' => false,
                'message'    => "Request is in '{$policyRequest->status}' state, not eligible for reconciliation.",
            ];
        }

        // Build the idempotency key (same as used during submission)
        $idempotencyKey = "policy-request-{$policyRequest->id}";

        // Query the mock insurer for an existing policy
        $providerResult = $this->mockInsurer->lookupPolicy($idempotencyKey);

        if ($providerResult && $providerResult['status'] === 'ACTIVE') {
            // Policy exists on provider side — recover it
            return DB::transaction(function () use ($policyRequest, $providerResult) {
                $policyRequest->policy_number = $providerResult['policy_number'];
                $policyRequest->provider_reference = $providerResult['provider_reference'];
                $policyRequest->last_response = json_encode([
                    'reconciliation' => true,
                    'message' => 'Policy recovered via reconciliation',
                ]);
                $policyRequest->save();

                $this->stateMachine->transition(
                    $policyRequest,
                    'ISSUED',
                    'Policy recovered via reconciliation from provider'
                );

                return [
                    'reconciled'    => true,
                    'policy_number' => $providerResult['policy_number'],
                    'message'       => 'Policy successfully recovered from provider.',
                ];
            });
        }

        // No policy found on provider side
        return [
            'reconciled' => false,
            'message'    => 'No policy found on provider side. Manual review may be needed.',
        ];
    }
}
