<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Calculates the insurance premium using integer paise arithmetic
 * to avoid floating-point rounding errors.
 *
 * Formula: base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
 * All multipliers are applied as integer math (multiply then divide by 100).
 */
class PremiumCalculator
{
    // Base premium per travel day in PAISE
    private const BASE_RATES_PAISE = [
        'BASIC'   => 1200,  // ₹12.00
        'PLUS'    => 2000,  // ₹20.00
        'PREMIUM' => 3000,  // ₹30.00
    ];

    // Age multipliers × 100 (to keep integer math)
    private const AGE_MULTIPLIERS = [
        '0-17'  => 110,  // 1.10
        '18-60' => 100,  // 1.00
        '61-70' => 150,  // 1.50
        '71+'   => 200,  // 2.00
    ];

    // Destination multipliers × 100
    private const DESTINATION_MULTIPLIERS = [
        'DOMESTIC'      => 100,  // 1.00
        'INTERNATIONAL' => 180,  // 1.80
    ];

    // Coverage multipliers × 100
    private const COVERAGE_MULTIPLIERS = [
        100000 => 100,  // 1.00
        300000 => 125,  // 1.25
        500000 => 150,  // 1.50
    ];

    /**
     * Calculate travel days (inclusive: trip_end - trip_start + 1).
     */
    public function calculateTravelDays(string|Carbon $tripStart, string|Carbon $tripEnd): int
    {
        $start = ($tripStart instanceof Carbon ? $tripStart->copy() : Carbon::parse($tripStart))->startOfDay();
        $end = ($tripEnd instanceof Carbon ? $tripEnd->copy() : Carbon::parse($tripEnd))->startOfDay();

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Calculate age on the trip start date.
     */
    public function calculateAge(string|Carbon $dateOfBirth, string|Carbon $tripStart): int
    {
        $dob = ($dateOfBirth instanceof Carbon ? $dateOfBirth->copy() : Carbon::parse($dateOfBirth))->startOfDay();
        $start = ($tripStart instanceof Carbon ? $tripStart->copy() : Carbon::parse($tripStart))->startOfDay();

        return (int) $dob->diff($start)->y;
    }

    /**
     * Get the age multiplier (× 100) based on age.
     */
    public function getAgeMultiplier(int $age): int
    {
        if ($age <= 17) return self::AGE_MULTIPLIERS['0-17'];
        if ($age <= 60) return self::AGE_MULTIPLIERS['18-60'];
        if ($age <= 70) return self::AGE_MULTIPLIERS['61-70'];
        return self::AGE_MULTIPLIERS['71+'];
    }

    /**
     * Calculate premium in paise (integer).
     *
     * Uses integer arithmetic throughout:
     * premium_paise = base_paise × days × (age_mult/100) × (dest_mult/100) × (cov_mult/100)
     *
     * We chain multiplications and divide at the end to minimize rounding.
     * Result = base_paise × days × age_mult × dest_mult × cov_mult / (100 × 100 × 100)
     */
    public function calculatePremiumPaise(
        string $planCode,
        string $destination,
        int $sumInsured,
        string|Carbon $dateOfBirth,
        string|Carbon $tripStart,
        string|Carbon $tripEnd
    ): int {
        $basePaise = self::BASE_RATES_PAISE[$planCode];
        $travelDays = $this->calculateTravelDays($tripStart, $tripEnd);
        $age = $this->calculateAge($dateOfBirth, $tripStart);
        $ageMult = $this->getAgeMultiplier($age);
        $destMult = self::DESTINATION_MULTIPLIERS[$destination];
        $covMult = self::COVERAGE_MULTIPLIERS[$sumInsured];

        // Integer arithmetic: multiply everything, then divide by 100^3 = 1,000,000
        // This gives us the result in paise, rounded to nearest integer.
        $numerator = $basePaise * $travelDays * $ageMult * $destMult * $covMult;

        return (int) round($numerator / 1_000_000);
    }

    /**
     * Calculate premium in rupees (as a float for display, 2 decimal places).
     */
    public function calculatePremiumRupees(
        string $planCode,
        string $destination,
        int $sumInsured,
        string|Carbon $dateOfBirth,
        string|Carbon $tripStart,
        string|Carbon $tripEnd
    ): float {
        $paise = $this->calculatePremiumPaise($planCode, $destination, $sumInsured, $dateOfBirth, $tripStart, $tripEnd);
        return round($paise / 100, 2);
    }

    /**
     * Check if a plan code is valid.
     */
    public static function isValidPlan(string $plan): bool
    {
        return isset(self::BASE_RATES_PAISE[$plan]);
    }

    /**
     * Check if a destination is valid.
     */
    public static function isValidDestination(string $destination): bool
    {
        return isset(self::DESTINATION_MULTIPLIERS[$destination]);
    }

    /**
     * Check if coverage amount is valid.
     */
    public static function isValidCoverage(int $sumInsured): bool
    {
        return isset(self::COVERAGE_MULTIPLIERS[$sumInsured]);
    }
}
