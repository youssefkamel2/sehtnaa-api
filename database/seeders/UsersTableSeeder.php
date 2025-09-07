<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
        // Create Super Admin
        // $superAdmin = User::create([
        //     'first_name' => 'Super',
        //     'last_name' => 'Admin',
        //     'email' => 'support@sehtnaa.com',
        //     'phone' => '01148474762',
        //     'password' => Hash::make('password'),
        //     'user_type' => 'admin',
        //     'status' => 'active',
        //     'address' => '123 Main Street',
        //     'gender' => 'male'
        // ]);

        // // Create Admin record
        // Admin::create([
        //     'user_id' => $superAdmin->id,
        //     'role' => 'super_admin'
        // ]);

        // Create Customer User
        $customerUser = User::create([
            'first_name' => 'Youssef',
            'last_name' => 'Kamel',
            'email' => 'youssefrdpcloud550@gmail.com',
            'phone' => '123457899',
            'password' => Hash::make('password'),
            'user_type' => 'customer',
            'status' => 'active',
            'address' => '123 Main St, City, Country',
            'gender' => 'male'
        ]);

        // Create Customer record
        Customer::create([
            'user_id' => $customerUser->id
        ]);

        // Create Individual Provider User
        $providerUser = User::create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'individual-provider@gmail.com',
            'phone' => '123456',
            'password' => Hash::make('password'),
            'user_type' => 'provider',
            'status' => 'active',
            'address' => '456 Elm St, City, Country',
            'gender' => 'female',
            'birth_date' => '2003-05-10'
        ]);

        // Create Provider record
        Provider::create([
            'user_id' => $providerUser->id,
            'provider_type' => 'individual',
            'nid' => '30305101503372'
        ]);
    }
}