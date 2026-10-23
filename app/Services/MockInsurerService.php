<?php

namespace App\Services;

use App\Models\MockInsurerPolicy;
use Illuminate\Support\Str;

/**
 * Simulates an external insurance provider.
 * Uses a database-backed store (mock_insurer_policies) to persist provider-side records.
 *
 * Scenario selection is controlled by a "scenario" parameter for testing.
 * In production, this would be replaced by a real HTTP client.
 *
 * Supported scenarios:
 *   - SUCCESS: Creates policy and returns it immediately.
 *   - TEMPORARY_FAILURE: Returns a transient error; safe to retry.
 *   - TIMEOUT_AFTER_ISSUE: Creates policy in provider DB but simulates a timeout response.
 *   - DELAYED_CALLBACK: Returns pending status; callback comes later (manual trigger).
 */
class MockInsurerService
{
    /**
     * Submit a policy issuance request to the mock insurer.
     *
     * @param string $idempotencyKey  Stable key derived from the policy request
     * @param array  $requestData     Data about the policy request
     * @param string $scenario        Test scenario to simulate
     * @return array Response from the mock insurer
     */
    public function submitRequest(string $idempotencyKey, array $requestData, string $scenario = 'SUCCESS'): array
    {
        // Check if policy already exists for this idempotency key (prevents duplicates)
        $existing = MockInsurerPolicy::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return [
                'success'            => true,
                'policy_number'      => $existing->policy_number,
                'provider_reference' => $existing->provider_reference,
                'message'            => 'Existing policy returned (idempotent)',
                'is_duplicate'       => true,
            ];
        }

        switch (strtoupper($scenario)) {
            case 'SUCCESS':
                return $this->handleSuccess($idempotencyKey, $requestData);

            case 'TEMPORARY_FAILURE':
                return $this->handleTemporaryFailure();

            case 'TIMEOUT_AFTER_ISSUE':
                return $this->handleTimeoutAfterIssue($idempotencyKey, $requestData);

            case 'DELAYED_CALLBACK':
                return $this->handleDelayedCallback($idempotencyKey, $requestData);

            default:
                return $this->handleSuccess($idempotencyKey, $requestData);
        }
    }

    /**
     * Look up a policy by idempotency key (used for reconciliation).
     */
    public function lookupPolicy(string $idempotencyKey): ?array
    {
        $policy = MockInsurerPolicy::where('idempotency_key', $idempotencyKey)->first();

        if (!$policy) {
            return null;
        }

        return [
            'policy_number'      => $policy->policy_number,
            'provider_reference' => $policy->provider_reference,
            'status'             => $policy->status,
        ];
    }

    /**
     * Scenario A: Successful issuance.
     */
    private function handleSuccess(string $idempotencyKey, array $requestData): array
    {
        $policy = MockInsurerPolicy::create([
            'idempotency_key'    => $idempotencyKey,
            'policy_number'      => 'POL-' . strtoupper(Str::random(10)),
            'provider_reference' => 'REF-' . strtoupper(Str::random(8)),
            'status'             => 'ACTIVE',
            'request_data'       => $requestData,
        ]);

        return [
            'success'            => true,
            'policy_number'      => $policy->policy_number,
            'provider_reference' => $policy->provider_reference,
            'message'            => 'Policy issued successfully',
            'is_duplicate'       => false,
        ];
    }

    /**
     * Scenario B: Temporary failure (no policy created).
     */
    private function handleTemporaryFailure(): array
    {
        return [
            'success'            => false,
            'policy_number'      => null,
            'provider_reference' => null,
            'message'            => 'Temporary service unavailable. Please retry.',
            'error_code'         => 'TEMP_FAILURE',
            'is_duplicate'       => false,
        ];
    }

    /**
     * Scenario C: Policy is created in provider DB, but the response "times out".
     * The caller won't see a success — they'll get a timeout error.
     * But the policy exists on the provider side for reconciliation.
     */
    private function handleTimeoutAfterIssue(string $idempotencyKey, array $requestData): array
    {
        // Create the policy on the provider side
        MockInsurerPolicy::create([
            'idempotency_key'    => $idempotencyKey,
            'policy_number'      => 'POL-' . strtoupper(Str::random(10)),
            'provider_reference' => 'REF-' . strtoupper(Str::random(8)),
            'status'             => 'ACTIVE',
            'request_data'       => $requestData,
        ]);

        // But return a timeout error to the caller
        return [
            'success'            => false,
            'policy_number'      => null,
            'provider_reference' => null,
            'message'            => 'Request timed out',
            'error_code'         => 'TIMEOUT',
            'is_duplicate'       => false,
        ];
    }

    /**
     * Scenario D: Delayed callback — returns pending, policy not yet created.
     * A separate callback endpoint is used to deliver the result later.
     */
    private function handleDelayedCallback(string $idempotencyKey, array $requestData): array
    {
        // Don't create the policy yet — it will be created when the callback is triggered
        return [
            'success'            => false,
            'policy_number'      => null,
            'provider_reference' => 'PENDING-REF-' . strtoupper(Str::random(8)),
            'message'            => 'Request accepted. Result will be delivered via callback.',
            'error_code'         => 'PENDING_CALLBACK',
            'is_duplicate'       => false,
        ];
    }

    /**
     * Simulate triggering a delayed callback — creates the policy in the provider DB.
     * Used for testing scenario D.
     */
    public function triggerDelayedIssue(string $idempotencyKey, array $requestData): array
    {
        $existing = MockInsurerPolicy::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return [
                'policy_number'      => $existing->policy_number,
                'provider_reference' => $existing->provider_reference,
                'is_new'             => false,
            ];
        }

        $policy = MockInsurerPolicy::create([
            'idempotency_key'    => $idempotencyKey,
            'policy_number'      => 'POL-' . strtoupper(Str::random(10)),
            'provider_reference' => 'REF-' . strtoupper(Str::random(8)),
            'status'             => 'ACTIVE',
            'request_data'       => $requestData,
        ]);

        return [
            'policy_number'      => $policy->policy_number,
            'provider_reference' => $policy->provider_reference,
            'is_new'             => true,
        ];
    }
}
