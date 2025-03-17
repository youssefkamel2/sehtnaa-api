<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        // Create the super admin user
        $user = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('123456'),
            'phone' => '797987987',
            'address' => '123 Main Street',
            'user_type' => 'admin',
            'status' => 'active',
        ]);

        // Assign the super admin role
        Admin::create([
            'user_id' => $user->id,
            'role' => 'super_admin',
        ]);
    }
}