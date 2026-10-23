<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    /**
     * Requirement 2: Correct premium calculation of ₹225.00 for the assignment example.
     *
     * Input:
     * - client_request_id: AGT-2026-0001
     * - agent_id: AGENT-17
     * - customer_name: Sample Customer
     * - date_of_birth: 1998-04-10
     * - destination: INTERNATIONAL
     * - trip_start: 2026-11-10
     * - trip_end: 2026-11-14
     * - plan_code: PLUS
     * - sum_insured: 300000
     *
     * Expected:
     * - Travel days: 5
     * - Age on trip start (1998 to 2026): 28 -> multiplier 1.00
     * - Destination: INTERNATIONAL -> multiplier 1.80
     * - Coverage: 300000 -> multiplier 1.25
     * - Base rate: PLUS = ₹20
     * - Premium: 20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00
     */
    public function test_assignment_example_calculates_exact_225_rupees(): void
    {
        $premiumRupees = $this->calculator->calculatePremiumRupees(
            planCode: 'PLUS',
            destination: 'INTERNATIONAL',
            sumInsured: 300000,
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $premiumPaise = $this->calculator->calculatePremiumPaise(
            planCode: 'PLUS',
            destination: 'INTERNATIONAL',
            sumInsured: 300000,
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $this->assertEquals(225.00, $premiumRupees);
        $this->assertEquals(22500, $premiumPaise);
    }

    /**
     * Requirement 3: Inclusive trip-day calculation: (trip_end - trip_start) + 1.
     * Minimum 1 day.
     */
    public function test_inclusive_trip_day_calculation(): void
    {
        // Single day trip (same start and end date) = 1 day
        $sameDay = $this->calculator->calculateTravelDays('2026-06-01', '2026-06-01');
        $this->assertEquals(1, $sameDay);

        // 5-day trip (10th to 14th inclusive) = 5 days
        $fiveDays = $this->calculator->calculateTravelDays('2026-11-10', '2026-11-14');
        $this->assertEquals(5, $fiveDays);

        // 10-day trip (1st to 10th inclusive) = 10 days
        $tenDays = $this->calculator->calculateTravelDays('2026-01-01', '2026-01-10');
        $this->assertEquals(10, $tenDays);
    }

    /**
     * Age multipliers based on age on trip start:
     * - Age 0–17: 1.10
     * - Age 18–60: 1.00
     * - Age 61–70: 1.50
     * - Age 71+: 2.00
     */
    public function test_age_multipliers_brackets(): void
    {
        // Child (10 years old) -> 1.10
        $this->assertEquals(110, $this->calculator->getAgeMultiplier(10));
        // Boundary: 17 years old -> 1.10
        $this->assertEquals(110, $this->calculator->getAgeMultiplier(17));
        // Adult: 18 years old -> 1.00
        $this->assertEquals(100, $this->calculator->getAgeMultiplier(18));
        // Adult: 40 years old -> 1.00
        $this->assertEquals(100, $this->calculator->getAgeMultiplier(40));
        // Boundary: 60 years old -> 1.00
        $this->assertEquals(100, $this->calculator->getAgeMultiplier(60));
        // Senior: 61 years old -> 1.50
        $this->assertEquals(150, $this->calculator->getAgeMultiplier(61));
        // Senior: 70 years old -> 1.50
        $this->assertEquals(150, $this->calculator->getAgeMultiplier(70));
        // Elder: 71 years old -> 2.00
        $this->assertEquals(200, $this->calculator->getAgeMultiplier(71));
        // Elder: 85 years old -> 2.00
        $this->assertEquals(200, $this->calculator->getAgeMultiplier(85));
    }
}
