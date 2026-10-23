# AI Usage Disclosure — PolicyFlow

This document satisfies the AI disclosure policy specified in Section 10 of the assignment guidelines.

---

## 1. AI Tools Used
- **Antigravity AI Assistant** (powered by Claude 3.7 Sonnet / Gemini models via DeepMind's agentic framework)
- Shell execution and file tooling within the local workspace

---

## 2. Concrete Ways AI Assisted with Implementation

1. **Scaffolding and Boilerplate Generation:**
   - Generated database migrations with composite unique keys (`agent_id` + `client_request_id`) and index definitions.
   - Built the Eloquent models (`PolicyRequest`, `IssuanceAttempt`, `ProviderEvent`, `PolicyStateHistory`, and `MockInsurerPolicy`).

2. **Decimal-Safe Financial Arithmetic:**
   - Implemented integer paise calculation (`PremiumCalculator`) for zero-rounding-drift currency math instead of IEEE 754 binary floating-point operations.

3. **Reliability and State Machine Orchestration:**
   - Structured the `PolicyStateMachine` with an explicit transition matrix to strictly reject invalid regressions (e.g., `ISSUED` → `SUBMITTED`).
   - Designed the deterministic mock insurer supporting test scenarios: `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, and `DUPLICATE_CALLBACK`.

4. **Automated Test Suite Authoring:**
   - Created 20 comprehensive unit and feature tests covering all 17 mandatory acceptance criteria and reliability scenarios.

---

## 3. Incomplete / Incorrect AI Suggestions Encountered and Corrected

During development, two notable AI-generated edge cases were caught and fixed:

1. **Carbon 3 Float Deprecation in Age Calculation:**
   - **Initial code:** The AI generated `$dob->diffInYears($start)` in `calculateAge()`.
   - **Problem:** In Carbon 3 on PHP 8.3, `diffInYears()` returned a floating-point number (e.g. `28.5863...`), triggering a PHP 8.3 deprecation warning: `"Implicit conversion from float to int loses precision"` and risking improper age bracket classification.
   - **Correction:** Manually reviewed and replaced with `(int) $dob->diff($start)->y` after normalizing both dates to `startOfDay()`.

2. **Overly Restrictive Intake Date Validation:**
   - **Initial code:** The AI suggested `'trip_start' => 'required|date|after_or_equal:today'`.
   - **Problem:** The assignment specifications only mandate that `trip_end >= trip_start` and `date_of_birth <= today`. Adding an unrequested `after_or_equal:today` rule would break automated tests and offline regression runs that evaluate specific fixed test dates.
   - **Correction:** Removed the `after_or_equal:today` constraint on `trip_start`, keeping strict adherence to the assignment requirements.

---

## 4. How the Code Was Tested and Verified

1. **Unit Testing:**
   - Verified the exact assignment example (`20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00`) against `PremiumCalculator`.
   - Verified inclusive travel day calculations and all age bracket boundaries (0–17, 18–60, 61–70, 71+).

2. **Feature & Integration Testing:**
   - Executed PHPUnit 12 on PHP 8.3.36 via `php artisan test --testdox`.
   - All 20 tests and 96 assertions passed with 100% success rate.

3. **Database Concurrency & Idempotency Check:**
   - Verified SQLite and MySQL compatible composite unique constraints on `(agent_id, client_request_id)` for duplicate intake protection.
   - Verified unique constraints on `provider_event_id` for callback deduplication.
   - Verified that status transitions prevent regressions from `ISSUED`.
