<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::create([
            'nama_depan' => 'Admin',
            'nama_belakang' => 'baco',
            'email' => 'adminbaco@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }
}
