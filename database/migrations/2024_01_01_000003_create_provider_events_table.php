<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id', 100)->unique();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->onDelete('cascade');
            $table->string('event_type', 50); // POLICY_ISSUED, POLICY_FAILED, etc.
            $table->json('event_data')->nullable();
            $table->string('processing_status', 30)->default('PENDING'); // PENDING, PROCESSED, DUPLICATE
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
