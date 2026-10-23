<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use App\Services\PremiumCalculator;
use App\Services\PolicyStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PolicyRequestController extends Controller
{
    public function __construct(
        private PremiumCalculator $premiumCalculator,
        private PolicyStateMachine $stateMachine,
    ) {}

    /**
     * POST /api/policy-requests
     * Create a new policy request or return an existing one (idempotent).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_request_id' => 'required|string|max:100',
            'agent_id'          => 'required|string|max:100',
            'customer_name'     => 'required|string|max:255',
            'date_of_birth'     => 'required|date|before_or_equal:today',
            'destination'       => 'required|string|in:DOMESTIC,INTERNATIONAL',
            'trip_start'        => 'required|date',
            'trip_end'          => 'required|date|after_or_equal:trip_start',
            'plan_code'         => 'required|string|in:BASIC,PLUS,PREMIUM',
            'sum_insured'       => 'required|integer|in:100000,300000,500000',
        ]);

        // Check for existing request with same idempotency key
        $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
            ->where('client_request_id', $validated['client_request_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Existing policy request returned',
                'data'    => $this->formatPolicyRequest($existing),
            ], 200);
        }

        // Calculate premium using integer paise arithmetic
        $premiumPaise = $this->premiumCalculator->calculatePremiumPaise(
            $validated['plan_code'],
            $validated['destination'],
            $validated['sum_insured'],
            $validated['date_of_birth'],
            $validated['trip_start'],
            $validated['trip_end']
        );

        try {
            $policyRequest = DB::transaction(function () use ($validated, $premiumPaise) {
                $pr = PolicyRequest::create([
                    'client_request_id' => $validated['client_request_id'],
                    'agent_id'          => $validated['agent_id'],
                    'customer_name'     => $validated['customer_name'],
                    'date_of_birth'     => $validated['date_of_birth'],
                    'destination'       => $validated['destination'],
                    'trip_start'        => $validated['trip_start'],
                    'trip_end'          => $validated['trip_end'],
                    'plan_code'         => $validated['plan_code'],
                    'sum_insured'       => $validated['sum_insured'],
                    'premium_paise'     => $premiumPaise,
                    'status'            => 'RECEIVED',
                ]);

                // Transition to VALIDATED immediately since validation passed
                $this->stateMachine->transition($pr, 'VALIDATED', 'Request validated on intake');

                return $pr;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle concurrent duplicate insert (unique constraint violation)
            if ($this->isDuplicateKeyError($e)) {
                $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
                    ->where('client_request_id', $validated['client_request_id'])
                    ->first();

                return response()->json([
                    'success' => true,
                    'message' => 'Existing policy request returned (concurrent duplicate)',
                    'data'    => $this->formatPolicyRequest($existing),
                ], 200);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Policy request created successfully',
            'data'    => $this->formatPolicyRequest($policyRequest),
        ], 201);
    }

    /**
     * GET /api/policy-requests/{id}
     * Retrieve a single policy request with its history.
     */
    public function show(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with('stateHistory', 'issuanceAttempts')->find($id);

        if (!$policyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatPolicyRequest($policyRequest, includeHistory: true),
        ]);
    }

    /**
     * GET /api/policy-requests
     * List policy requests with optional status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $policyRequests = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $policyRequests->getCollection()->map(
                fn($pr) => $this->formatPolicyRequest($pr)
            ),
            'meta'    => [
                'current_page' => $policyRequests->currentPage(),
                'per_page'     => $policyRequests->perPage(),
                'total'        => $policyRequests->total(),
                'last_page'    => $policyRequests->lastPage(),
            ],
        ]);
    }

    /**
     * Format a policy request for the JSON response.
     */
    private function formatPolicyRequest(PolicyRequest $pr, bool $includeHistory = false): array
    {
        $data = [
            'id'                => $pr->id,
            'client_request_id' => $pr->client_request_id,
            'agent_id'          => $pr->agent_id,
            'customer_name'     => $pr->customer_name,
            'date_of_birth'     => $pr->date_of_birth->format('Y-m-d'),
            'destination'       => $pr->destination,
            'trip_start'        => $pr->trip_start->format('Y-m-d'),
            'trip_end'          => $pr->trip_end->format('Y-m-d'),
            'plan_code'         => $pr->plan_code,
            'sum_insured'       => $pr->sum_insured,
            'premium'           => $pr->premium,
            'status'            => $pr->status,
            'policy_number'     => $pr->policy_number,
            'provider_reference' => $pr->provider_reference,
            'attempt_count'     => $pr->attempt_count,
            'created_at'        => $pr->created_at?->toISOString(),
            'updated_at'        => $pr->updated_at?->toISOString(),
        ];

        if ($includeHistory) {
            $data['state_history'] = $pr->stateHistory->map(fn($h) => [
                'previous_state' => $h->previous_state,
                'new_state'      => $h->new_state,
                'reason'         => $h->reason,
                'created_at'     => $h->created_at?->toISOString(),
            ]);

            $data['issuance_attempts'] = $pr->issuanceAttempts->map(fn($a) => [
                'attempt_number'    => $a->attempt_number,
                'status'            => $a->status,
                'provider_reference' => $a->provider_reference,
                'response_summary'  => $a->response_summary,
                'created_at'        => $a->created_at?->toISOString(),
            ]);
        }

        return $data;
    }

    /**
     * Check if a query exception is a duplicate key violation.
     */
    private function isDuplicateKeyError(\Illuminate\Database\QueryException $e): bool
    {
        // SQLite: UNIQUE constraint failed
        // MySQL: Duplicate entry (error code 23000)
        $code = (string) $e->getCode();
        return $code === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
