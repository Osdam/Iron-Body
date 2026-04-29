<?php

namespace Database\Seeders;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $basic = Plan::firstOrCreate(
            ['name' => 'Mensual'],
            [
                'price' => 39.99,
                'duration_days' => 30,
                'benefits' => 'Acceso a sala general y una valoración mensual.',
                'active' => true,
            ]
        );

        Plan::firstOrCreate(
            ['name' => 'Elite'],
            [
                'price' => 79.99,
                'duration_days' => 30,
                'benefits' => 'Clases ilimitadas, rutinas personalizadas y reservas preferentes.',
                'active' => true,
            ]
        );

        Payment::firstOrCreate(
            ['reference' => 'DEMO-001'],
            [
                'user_id' => $user->id,
                'plan_id' => $basic->id,
                'amount' => 39.99,
                'method' => 'cash',
                'status' => 'paid',
                'paid_at' => now(),
            ]
        );
    }
}
