<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->onDelete('cascade');
            $table->string('previous_state', 30)->nullable();
            $table->string('new_state', 30);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('related_attempt_id')->nullable();
            $table->unsignedBigInteger('related_event_id')->nullable();
            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
