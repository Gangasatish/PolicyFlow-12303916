<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\MockInsurerPolicy;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'client_request_id' => 'AGT-2026-0001',
        'agent_id'          => 'AGENT-17',
        'customer_name'     => 'Sample Customer',
        'date_of_birth'     => '1998-04-10',
        'destination'       => 'INTERNATIONAL',
        'trip_start'        => '2026-11-10',
        'trip_end'          => '2026-11-14',
        'plan_code'         => 'PLUS',
        'sum_insured'       => 300000,
    ];

    /**
     * Requirement 1 & 2: Valid request intake produces HTTP 201, VALIDATED status, and ₹225.00 premium.
     */
    public function test_valid_request_creation_and_exact_premium_calculation(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'client_request_id' => 'AGT-2026-0001',
                    'agent_id'          => 'AGENT-17',
                    'customer_name'     => 'Sample Customer',
                    'destination'       => 'INTERNATIONAL',
                    'plan_code'         => 'PLUS',
                    'sum_insured'       => 300000,
                    'premium'           => 225.00,
                    'status'            => 'VALIDATED',
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id'          => 'AGENT-17',
            'premium_paise'     => 22500,
            'status'            => 'VALIDATED',
        ]);
    }

    /**
     * Requirement 4: Invalid travel dates return 422 (trip_end before trip_start).
     */
    public function test_invalid_travel_dates_return_422(): void
    {
        $payload = array_merge($this->validPayload, [
            'trip_start' => '2026-11-14',
            'trip_end'   => '2026-11-10', // end before start
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    /**
     * Requirement 4b: Future date of birth returns 422.
     */
    public function test_future_date_of_birth_returns_422(): void
    {
        $payload = array_merge($this->validPayload, [
            'date_of_birth' => '2099-01-01',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    /**
     * Requirement 5: Unsupported plan returns 422.
     */
    public function test_unsupported_plan_returns_422(): void
    {
        $payload = array_merge($this->validPayload, [
            'plan_code' => 'ULTRA_SUPER_PLAN',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    /**
     * Requirement 6: Unsupported coverage amount returns 422.
     */
    public function test_unsupported_coverage_returns_422(): void
    {
        $payload = array_merge($this->validPayload, [
            'sum_insured' => 250000, // Only 100000, 300000, 500000 allowed
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    /**
     * Requirement 7: Invalid destination returns 422.
     */
    public function test_invalid_destination_returns_422(): void
    {
        $payload = array_merge($this->validPayload, [
            'destination' => 'INTERPLANETARY',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    /**
     * Reliability Case 1 (Requirements 8 & 9): Duplicate intake.
     * Repeated submission returns existing request with HTTP 200 and does NOT create a second row.
     */
    public function test_duplicate_intake_returns_existing_request_with_200(): void
    {
        // First submission -> 201 Created
        $firstResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $firstResponse->assertStatus(201);
        $firstId = $firstResponse->json('data.id');

        // Second submission with exact same agent_id and client_request_id -> 200 OK
        $secondResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $secondResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Existing policy request returned',
                'data'    => [
                    'id'                => $firstId,
                    'client_request_id' => 'AGT-2026-0001',
                    'agent_id'          => 'AGENT-17',
                ],
            ]);

        // Verify only 1 logical record exists in the database
        $this->assertEquals(1, PolicyRequest::where('agent_id', 'AGENT-17')
            ->where('client_request_id', 'AGT-2026-0001')
            ->count());
    }

    /**
     * Requirement 10: Successful issuance creates exactly one policy number.
     */
    public function test_successful_issuance_creates_unique_policy_number(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createRes->json('data.id');

        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);

        $submitRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'status' => 'ISSUED',
                ],
            ]);

        $policyNumber = $submitRes->json('data.policy_number');
        $this->assertNotNull($policyNumber);
        $this->assertStringStartsWith('POL-', $policyNumber);

        // Verify state in DB
        $policy = PolicyRequest::find($id);
        $this->assertEquals('ISSUED', $policy->status);
        $this->assertEquals($policyNumber, $policy->policy_number);
    }

    /**
     * Requirement 11: Repeated submission of an already ISSUED policy does not create another policy.
     */
    public function test_repeated_submission_returns_existing_policy_safely(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createRes->json('data.id');

        // First submission: SUCCESS -> ISSUED
        $firstSubmit = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);
        $firstPolicyNumber = $firstSubmit->json('data.policy_number');

        // Repeated submission
        $secondSubmit = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);
        $secondSubmit->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'policy_number' => $firstPolicyNumber,
                    'status'        => 'ISSUED',
                ],
            ]);

        // Policy count in mock insurer must remain exactly 1
        $this->assertEquals(1, MockInsurerPolicy::where('idempotency_key', "policy-request-{$id}")->count());
    }

    /**
     * Reliability Case 2 (Requirements 12 & 13): Timeout after issuance and reconciliation.
     * Insurer issues policy internally but client times out.
     * System marks request as NEEDS_RECONCILIATION.
     * Reconciliation recovers the existing policy without issuing a duplicate.
     */
    public function test_timeout_after_issue_and_reconciliation_recovery(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createRes->json('data.id');

        // Submit with TIMEOUT_AFTER_ISSUE scenario
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $submitRes->assertStatus(200)
            ->assertJson([
                'success' => false,
                'data'    => [
                    'status' => 'NEEDS_RECONCILIATION',
                ],
            ]);

        // Check local state is NEEDS_RECONCILIATION, policy_number is not set yet locally
        $localPolicy = PolicyRequest::find($id);
        $this->assertEquals('NEEDS_RECONCILIATION', $localPolicy->status);
        $this->assertNull($localPolicy->policy_number);

        // However, the provider side DID create the policy
        $providerPolicy = MockInsurerPolicy::where('idempotency_key', "policy-request-{$id}")->first();
        $this->assertNotNull($providerPolicy);
        $this->assertStringStartsWith('POL-', $providerPolicy->policy_number);

        // Now trigger reconciliation
        $reconcileRes = $this->postJson("/api/policy-requests/{$id}/reconcile");
        $reconcileRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'status'        => 'ISSUED',
                    'policy_number' => $providerPolicy->policy_number,
                ],
            ]);

        // Verify local request is now ISSUED with the recovered policy number
        $localPolicy->refresh();
        $this->assertEquals('ISSUED', $localPolicy->status);
        $this->assertEquals($providerPolicy->policy_number, $localPolicy->policy_number);

        // Verify provider still only has 1 policy record (no duplicate was created)
        $this->assertEquals(1, MockInsurerPolicy::where('idempotency_key', "policy-request-{$id}")->count());
    }

    /**
     * Reliability Case 3 (Requirement 14): Duplicate callback processing is idempotent.
     * Same provider event delivered multiple times is applied only once.
     */
    public function test_duplicate_callback_does_not_repeat_processing_or_state_change(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createRes->json('data.id');

        // Move request to SUBMITTED via temporary failure or pending
        $policyRequest = PolicyRequest::find($id);
        $policyRequest->status = 'SUBMITTED';
        $policyRequest->save();

        $callbackPayload = [
            'event_id'           => 'EVT-999-XYZ',
            'policy_request_id'  => $id,
            'event_type'         => 'POLICY_ISSUED',
            'policy_number'      => 'POL-FROM-CALLBACK-123',
            'provider_reference' => 'REF-FROM-CALLBACK-456',
        ];

        // First callback
        $firstCallback = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $firstCallback->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'new_status' => 'ISSUED',
                ],
            ]);

        $this->assertEquals('ISSUED', $policyRequest->fresh()->status);
        $this->assertEquals('POL-FROM-CALLBACK-123', $policyRequest->fresh()->policy_number);

        // Duplicate callback with the exact same event_id
        $secondCallback = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $secondCallback->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Event already processed (duplicate)',
            ]);

        // Third callback after ISSUED state
        $thirdCallback = $this->postJson('/api/mock-insurer/callback', array_merge($callbackPayload, [
            'event_id' => 'EVT-NEW-BUT-ALREADY-ISSUED',
        ]));
        $thirdCallback->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Policy already issued. Callback noted but no state change.',
            ]);

        // State remains ISSUED
        $this->assertEquals('ISSUED', $policyRequest->fresh()->status);
        $this->assertEquals('POL-FROM-CALLBACK-123', $policyRequest->fresh()->policy_number);
    }

    /**
     * Requirement 15: An ISSUED request cannot regress to an earlier state (SUBMITTED, FAILED, etc.).
     */
    public function test_issued_request_cannot_regress_to_earlier_state(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createRes->json('data.id');

        // Issue the policy
        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);
        $this->assertEquals('ISSUED', PolicyRequest::find($id)->status);

        // Attempt to submit again with TEMPORARY_FAILURE scenario — should return 200 with existing ISSUED data
        $retryRes = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'TEMPORARY_FAILURE']);
        $retryRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'status' => 'ISSUED',
                ],
            ]);

        // Verify status has NOT regressed
        $this->assertEquals('ISSUED', PolicyRequest::find($id)->status);
    }

    /**
     * Requirement 16: Listing supports status filtering and pagination.
     */
    public function test_listing_supports_status_filtering_and_pagination(): void
    {
        // Create 3 requests
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/policy-requests', array_merge($this->validPayload, [
                'client_request_id' => "AGT-PAG-{$i}",
            ]));
        }

        // Test pagination (per_page = 2)
        $pagedRes = $this->getJson('/api/policy-requests?per_page=2');
        $pagedRes->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
        $this->assertCount(2, $pagedRes->json('data'));
        $this->assertEquals(3, $pagedRes->json('meta.total'));

        // Test status filtering
        $filterRes = $this->getJson('/api/policy-requests?status=VALIDATED');
        $filterRes->assertStatus(200);
        $this->assertCount(3, $filterRes->json('data'));

        $emptyFilter = $this->getJson('/api/policy-requests?status=FAILED');
        $emptyFilter->assertStatus(200);
        $this->assertCount(0, $emptyFilter->json('data'));
    }

    /**
     * Requirement 17: Missing requests return 404.
     */
    public function test_missing_request_returns_404(): void
    {
        $this->getJson('/api/policy-requests/999999')->assertStatus(404);
        $this->postJson('/api/policy-requests/999999/submit')->assertStatus(404);
        $this->postJson('/api/policy-requests/999999/reconcile')->assertStatus(404);
    }

    /**
     * Reliability Scenario: Safe retry after temporary failure preserves original premium snapshot.
     */
    public function test_safe_retry_after_temporary_failure_preserves_original_premium(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createRes->json('data.id');
        $originalPremium = $createRes->json('data.premium');

        // First submit: TEMPORARY_FAILURE
        $failSubmit = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'TEMPORARY_FAILURE']);
        $failSubmit->assertStatus(200);
        $this->assertEquals('FAILED', PolicyRequest::find($id)->status);

        // Safe retry: SUCCESS
        $retrySubmit = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);
        $retrySubmit->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'status'        => 'ISSUED',
                    'premium'       => $originalPremium,
                    'attempt_count' => 2,
                ],
            ]);

        // Premium has not changed
        $policy = PolicyRequest::find($id);
        $this->assertEquals($originalPremium, $policy->premium);
        $this->assertEquals('ISSUED', $policy->status);
        $this->assertEquals(2, $policy->attempt_count);
    }
}
