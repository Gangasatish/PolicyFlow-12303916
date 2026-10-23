<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use App\Models\IssuanceAttempt;
use App\Services\MockInsurerService;
use App\Services\PolicyStateMachine;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IssuanceController extends Controller
{
    public function __construct(
        private MockInsurerService $mockInsurer,
        private PolicyStateMachine $stateMachine,
        private ReconciliationService $reconciliation,
    ) {}

    /**
     * POST /api/policy-requests/{id}/submit
     * Submit a policy request to the mock insurer for issuance.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::find($id);

        if (!$policyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found',
            ], 404);
        }

        // If already ISSUED, return the existing result
        if ($policyRequest->status === 'ISSUED') {
            return response()->json([
                'success' => true,
                'message' => 'Policy already issued',
                'data'    => [
                    'id'                 => $policyRequest->id,
                    'status'             => $policyRequest->status,
                    'policy_number'      => $policyRequest->policy_number,
                    'provider_reference' => $policyRequest->provider_reference,
                    'premium'            => $policyRequest->premium,
                ],
            ], 200);
        }

        // Check if eligible for submission
        $submittableStates = ['VALIDATED', 'FAILED', 'NEEDS_RECONCILIATION'];
        if (!in_array($policyRequest->status, $submittableStates, true)) {
            // If in uncertain state, don't submit again
            if (in_array($policyRequest->status, ['SUBMITTED', 'PENDING_CONFIRMATION'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot submit: request is in '{$policyRequest->status}' state. Outcome is still uncertain.",
                ], 409);
            }
            return response()->json([
                'success' => false,
                'message' => "Cannot submit: request is in '{$policyRequest->status}' state",
            ], 409);
        }

        // Determine scenario (test-only parameter, defaults to SUCCESS)
        $scenario = $request->input('scenario', 'SUCCESS');

        // Build stable idempotency key for the provider
        $idempotencyKey = "policy-request-{$policyRequest->id}";

        // Transition to SUBMITTED
        try {
            $this->stateMachine->transition($policyRequest, 'SUBMITTED', 'Submitting to insurer');
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }

        // Increment attempt count
        $policyRequest->increment('attempt_count');
        $attemptNumber = $policyRequest->attempt_count;

        // Call the mock insurer (outside DB transaction — simulates external call)
        $response = $this->mockInsurer->submitRequest($idempotencyKey, [
            'customer_name' => $policyRequest->customer_name,
            'destination'   => $policyRequest->destination,
            'plan_code'     => $policyRequest->plan_code,
            'premium'       => $policyRequest->premium,
        ], $scenario);

        // Record the attempt and update state based on response
        return DB::transaction(function () use ($policyRequest, $response, $attemptNumber, $scenario) {
            $attemptStatus = $response['success'] ? 'SUCCESS' : ($response['error_code'] ?? 'FAILED');

            $attempt = IssuanceAttempt::create([
                'policy_request_id' => $policyRequest->id,
                'attempt_number'    => $attemptNumber,
                'status'            => $attemptStatus,
                'provider_reference' => $response['provider_reference'] ?? null,
                'response_summary'  => $response['message'] ?? null,
                'error_details'     => $response['success'] ? null : json_encode($response),
            ]);

            $policyRequest->last_response = json_encode($response);
            if ($response['provider_reference'] ?? null) {
                $policyRequest->provider_reference = $response['provider_reference'];
            }

            if ($response['success']) {
                // Successful issuance
                $policyRequest->policy_number = $response['policy_number'];
                $policyRequest->save();

                $this->stateMachine->transition(
                    $policyRequest,
                    'ISSUED',
                    'Policy issued by insurer',
                    $attempt->id
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Policy issued successfully',
                    'data'    => [
                        'id'                 => $policyRequest->id,
                        'status'             => $policyRequest->status,
                        'policy_number'      => $policyRequest->policy_number,
                        'provider_reference' => $policyRequest->provider_reference,
                        'premium'            => $policyRequest->premium,
                        'attempt_count'      => $policyRequest->attempt_count,
                    ],
                ], 200);
            }

            // Handle failure scenarios
            $errorCode = $response['error_code'] ?? 'UNKNOWN';

            if ($errorCode === 'TIMEOUT') {
                // Timeout — outcome uncertain, needs reconciliation
                $policyRequest->save();
                $this->stateMachine->transition(
                    $policyRequest,
                    'PENDING_CONFIRMATION',
                    'Insurer request timed out — outcome uncertain',
                    $attempt->id
                );

                // Move to NEEDS_RECONCILIATION since we can't confirm
                $this->stateMachine->transition(
                    $policyRequest,
                    'NEEDS_RECONCILIATION',
                    'Marking for reconciliation after timeout',
                    $attempt->id
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Request timed out. Outcome uncertain. Use reconciliation to check.',
                    'data'    => [
                        'id'            => $policyRequest->id,
                        'status'        => $policyRequest->status,
                        'attempt_count' => $policyRequest->attempt_count,
                    ],
                ], 200);
            }

            if ($errorCode === 'PENDING_CALLBACK') {
                // Pending callback — wait for async result
                $policyRequest->save();
                $this->stateMachine->transition(
                    $policyRequest,
                    'PENDING_CONFIRMATION',
                    'Awaiting callback from insurer',
                    $attempt->id
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Request accepted by insurer. Awaiting callback.',
                    'data'    => [
                        'id'                 => $policyRequest->id,
                        'status'             => $policyRequest->status,
                        'provider_reference' => $policyRequest->provider_reference,
                        'attempt_count'      => $policyRequest->attempt_count,
                    ],
                ], 200);
            }

            // Temporary or permanent failure — mark as FAILED, allow retry
            $policyRequest->save();
            $this->stateMachine->transition(
                $policyRequest,
                'FAILED',
                "Insurer returned error: {$errorCode}",
                $attempt->id
            );

            return response()->json([
                'success' => false,
                'message' => $response['message'] ?? 'Issuance failed',
                'data'    => [
                    'id'            => $policyRequest->id,
                    'status'        => $policyRequest->status,
                    'attempt_count' => $policyRequest->attempt_count,
                    'error'         => $errorCode,
                ],
            ], 200);
        });
    }

    /**
     * POST /api/policy-requests/{id}/reconcile
     * Reconcile a policy request with uncertain outcome against the provider.
     */
    public function reconcile(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::find($id);

        if (!$policyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found',
            ], 404);
        }

        $result = $this->reconciliation->reconcile($policyRequest);

        return response()->json([
            'success' => $result['reconciled'],
            'message' => $result['message'],
            'data'    => [
                'id'            => $policyRequest->id,
                'status'        => $policyRequest->fresh()->status,
                'policy_number' => $result['policy_number'] ?? $policyRequest->policy_number,
            ],
        ], $result['reconciled'] ? 200 : 200);
    }
}
