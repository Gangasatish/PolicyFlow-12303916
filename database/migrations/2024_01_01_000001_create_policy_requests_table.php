<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('customer_name', 255);
            $table->date('date_of_birth');
            $table->string('destination', 20); // DOMESTIC or INTERNATIONAL
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 20); // BASIC, PLUS, PREMIUM
            $table->unsignedBigInteger('sum_insured'); // stored in rupees: 100000, 300000, 500000
            $table->unsignedBigInteger('premium_paise'); // premium in paise for decimal-safe storage
            $table->string('status', 30)->default('RECEIVED');
            $table->string('policy_number', 100)->nullable()->unique();
            $table->string('provider_reference', 100)->nullable();
            $table->text('last_response')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamps();

            // Composite unique key for idempotency
            $table->unique(['agent_id', 'client_request_id'], 'unique_agent_client_request');

            $table->index('status');
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
