# PolicyFlow API — Sample cURL Commands

Base URL: `http://127.0.0.1:8000`

---

### 1. Create a New Policy Request (Intake)
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```

---

### 2. Retrieve Policy Request by ID (with Audit History)
```bash
curl -X GET http://127.0.0.1:8000/api/policy-requests/1 \
  -H "Accept: application/json"
```

---

### 3. List Policy Requests (Filtering & Pagination)
```bash
# List all requests with pagination
curl -X GET "http://127.0.0.1:8000/api/policy-requests?per_page=10" \
  -H "Accept: application/json"

# Filter by status
curl -X GET "http://127.0.0.1:8000/api/policy-requests?status=ISSUED" \
  -H "Accept: application/json"
```

---

### 4. Submit Policy Request for Issuance (Simulating Scenarios)
```bash
# Normal successful issuance
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "SUCCESS"}'

# Simulate timeout after issuance (Reliability Case 2)
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "TIMEOUT_AFTER_ISSUE"}'

# Simulate temporary failure (safe retry)
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "TEMPORARY_FAILURE"}'
```

---

### 5. Reconcile Policy Request (Recover from Timeout)
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/reconcile \
  -H "Content-Type: application/json" \
  -H "Accept: application/json"
```

---

### 6. Provider Callback (Asynchronous Issuance Event)
```bash
curl -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "event_id": "EVT-2026-88910",
    "policy_request_id": 1,
    "event_type": "POLICY_ISSUED",
    "policy_number": "POL-CALLBACK-98765",
    "provider_reference": "REF-CB-12345"
  }'
```
