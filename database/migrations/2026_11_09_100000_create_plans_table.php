<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Money in minor units (cents) as an integer, never a float (D8).
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3); // ISO 4217, e.g. EUR
            $table->string('billing_period')->default('monthly');
            // Inactive plans stay in the table (old subscriptions point at them) but can't be bought.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
