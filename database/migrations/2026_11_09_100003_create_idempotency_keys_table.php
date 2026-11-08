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
            // SHA-256 of the validated request body: same key + different body is rejected (D5).
            $table->char('request_hash', 64);
            $table->string('status'); // App\Enums\IdempotencyStatus
            // The outcome of the first request. A retry rebuilds the same response from the
            // subscription it points to (active -> 201, failed -> 402) instead of storing raw HTTP (D2).
            $table->foreignId('subscription_id')->nullable()->constrained();
            $table->timestamps();

            // The core of idempotency: the database, not PHP, guarantees that only one
            // request per user and key can ever be claimed, even under concurrency (D3, D4).
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
