<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\PolicyStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CallbackController extends Controller
{
    public function __construct(
        private PolicyStateMachine $stateMachine,
    ) {}

    /**
     * POST /api/mock-insurer/callback
     * Receive a callback event from the mock insurer.
     * Ensures each event is processed exactly once.
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id'           => 'required|string|max:100',
            'policy_request_id'  => 'required|integer',
            'event_type'         => 'required|string|in:POLICY_ISSUED,POLICY_FAILED',
            'policy_number'      => 'nullable|string|max:100',
            'provider_reference' => 'nullable|string|max:100',
        ]);

        // Check if this event was already received (idempotent)
        $existingEvent = ProviderEvent::where('provider_event_id', $validated['event_id'])->first();
        if ($existingEvent) {
            return response()->json([
                'success' => true,
                'message' => 'Event already processed (duplicate)',
                'data'    => [
                    'event_id'          => $existingEvent->provider_event_id,
                    'processing_status' => $existingEvent->processing_status,
                ],
            ], 200);
        }

        // Find the policy request
        $policyRequest = PolicyRequest::find($validated['policy_request_id']);
        if (!$policyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found',
            ], 404);
        }

        // Process the event in a transaction
        return DB::transaction(function () use ($validated, $policyRequest) {
            // Create the event record (unique constraint on provider_event_id prevents duplicates)
            try {
                $event = ProviderEvent::create([
                    'provider_event_id'  => $validated['event_id'],
                    'policy_request_id'  => $policyRequest->id,
                    'event_type'         => $validated['event_type'],
                    'event_data'         => $validated,
                    'processing_status'  => 'PENDING',
                    'received_at'        => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Concurrent duplicate insert
                if (str_contains($e->getMessage(), 'UNIQUE constraint failed') || $e->getCode() === '23000') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Event already processed (concurrent duplicate)',
                    ], 200);
                }
                throw $e;
            }

            // Don't overwrite an ISSUED state
            if ($policyRequest->status === 'ISSUED') {
                $event->processing_status = 'PROCESSED';
                $event->processed_at = now();
                $event->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Policy already issued. Callback noted but no state change.',
                    'data'    => [
                        'event_id' => $event->provider_event_id,
                        'status'   => $policyRequest->status,
                    ],
                ], 200);
            }

            // Process the callback based on event type
            if ($validated['event_type'] === 'POLICY_ISSUED') {
                $policyRequest->policy_number = $validated['policy_number'];
                $policyRequest->provider_reference = $validated['provider_reference'] ?? $policyRequest->provider_reference;
                $policyRequest->last_response = json_encode([
                    'source'  => 'callback',
                    'event'   => $validated,
                ]);
                $policyRequest->save();

                // Transition to ISSUED (handle various current states)
                try {
                    $this->stateMachine->transition(
                        $policyRequest,
                        'ISSUED',
                        'Policy issued via callback',
                        relatedEventId: $event->id
                    );
                } catch (\InvalidArgumentException $e) {
                    // If transition not allowed from current state, note it
                    $event->processing_status = 'PROCESSED';
                    $event->processed_at = now();
                    $event->save();

                    return response()->json([
                        'success' => true,
                        'message' => "Callback received but transition not allowed: {$e->getMessage()}",
                        'data'    => [
                            'event_id' => $event->provider_event_id,
                            'status'   => $policyRequest->status,
                        ],
                    ], 200);
                }
            } elseif ($validated['event_type'] === 'POLICY_FAILED') {
                try {
                    $this->stateMachine->transition(
                        $policyRequest,
                        'FAILED',
                        'Policy failed via callback',
                        relatedEventId: $event->id
                    );
                } catch (\InvalidArgumentException $e) {
                    // Ignore if transition not valid
                }
            }

            $event->processing_status = 'PROCESSED';
            $event->processed_at = now();
            $event->save();

            return response()->json([
                'success' => true,
                'message' => 'Callback processed successfully',
                'data'    => [
                    'event_id'       => $event->provider_event_id,
                    'policy_request' => $policyRequest->id,
                    'new_status'     => $policyRequest->fresh()->status,
                ],
            ], 200);
        });
    }
}
