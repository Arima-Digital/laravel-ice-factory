<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class CreateUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seedUsers = [
            [
                'username' => 'admin',
                'email' => 'admin@icefactory.com',
                'password_hash' => bcrypt('password123'),
                'role' => 'ADMIN',
            ],
            [
                'username' => 'warehouse_staff',
                'email' => 'warehouse@icefactory.com',
                'password_hash' => bcrypt('password123'),
                'role' => 'WAREHOUSE',
            ],
            [
                'username' => 'driver_2',
                'email' => 'driver2@icefactory.com',
                'password_hash' => bcrypt('password123'),
                'role' => 'DRIVER',
            ],
        ];

        foreach ($seedUsers as $userData) {
            User::firstOrCreate(
                ['email' => $userData['email']],
                $userData
            );
        }
    }
}
