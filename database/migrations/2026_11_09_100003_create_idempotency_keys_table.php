<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('key');
            $table->char('request_hash', 64); // SHA-256 of the request body
            $table->string('status');
            $table->foreignId('subscription_id')->nullable()->constrained();
            $table->timestamps();

            // The core of idempotency: the database guarantees one claim per user and key,
            // even under concurrency.
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
