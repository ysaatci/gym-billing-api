<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development data only. Both users get the factory's dev password ("password").
 * Plans and the admin are seeded because there is no plan management API (see README).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        User::factory()->create([
            'name' => 'Member',
            'email' => 'member@example.com',
        ]);

        Plan::factory()->create(['name' => 'Basic', 'price_cents' => 2999]);
        Plan::factory()->create(['name' => 'Premium', 'price_cents' => 4999]);
        // Inactive: exists for old subscriptions, but validation must reject new purchases.
        Plan::factory()->inactive()->create(['name' => 'Legacy', 'price_cents' => 1999]);
    }
}
