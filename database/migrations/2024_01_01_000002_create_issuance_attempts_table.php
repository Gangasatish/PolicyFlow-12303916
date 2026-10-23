<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->onDelete('cascade');
            $table->unsignedInteger('attempt_number');
            $table->string('status', 30); // SUCCESS, FAILED, TIMEOUT, PENDING
            $table->string('provider_reference', 100)->nullable();
            $table->text('response_summary')->nullable();
            $table->text('error_details')->nullable();
            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
