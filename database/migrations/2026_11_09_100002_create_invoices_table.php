<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained();
            // Snapshot of the plan price at purchase time, so a later price change
            // doesn't rewrite what the customer was actually charged (D8).
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('status'); // App\Enums\InvoiceStatus
            $table->string('gateway_reference')->nullable(); // the gateway's charge ID, once paid
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
