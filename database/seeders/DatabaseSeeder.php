<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * كلمة المرور لكل المستخدمين: password
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'seller@example.com'],
            [
                'name' => 'بائع تجريبي',
                'password' => 'password',
                'role' => 'seller',
            ]
        );
    }
}
