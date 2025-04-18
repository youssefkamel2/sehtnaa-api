<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Request as ServiceRequest;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class RequestsTableSeeder extends Seeder
{
    public function run()
    {
        // Get the customer user and ensure they have a customer record
        $customerUser = User::where('email', 'youssefrdpcloud550@gmail.com')->first();
        $customer = Customer::firstOrCreate(
            ['user_id' => $customerUser->id],
            ['additional_info' => null]
        );

        // Get the provider
        $providerUser = User::where('email', 'individual-provider@gmail.com')->first();
        
        $services = Service::limit(5)->get();

        $requests = [
            [
                'phone' => '123456789',
                'status' => 'pending',
                'additional_info' => 'Need weekly visits',
            ],
            [
                'phone' => '987654321',
                'status' => 'accepted',
                'additional_info' => 'Urgent care needed',
            ],
            [
                'phone' => '555123456',
                'status' => 'completed',
                'additional_info' => 'Regular checkup',
            ],
            [
                'phone' => '111222333',
                'status' => 'cancelled',
                'additional_info' => 'No longer needed',
            ],
            [
                'phone' => '444555666',
                'status' => 'accepted',
                'additional_info' => 'Post-surgery care',
            ]
        ];

        foreach ($requests as $index => $requestData) {
            ServiceRequest::create(array_merge($requestData, [
                'customer_id' => $customer->id, // Use the customer ID, not user ID
                'service_id' => $services[$index]->id,
                'assigned_provider_id' => $providerUser->provider->id,
                'latitude' => 30.0 + ($index * 0.5),
                'longitude' => 31.0 + ($index * 0.5),
            ]));
        }
    }
}