<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class ServicesTableSeeder extends Seeder
{
    public function run()
    {
        $admin = User::where('email', 'superadmin@example.com')->first();
        $categories = Category::all();

        $services = [
            [
                'name' => [
                    'en' => 'Chronic Disease Management',
                    'ar' => 'إدارة الأمراض المزمنة'
                ],
                'description' => [
                    'en' => 'Comprehensive management for chronic conditions',
                    'ar' => 'إدارة شاملة للحالات المزمنة'
                ],
                'price' => 200.00,
                'provider_type' => 'individual',
                'is_active' => true,
                'icon' => 'service_icons/service_icon.png',
                'cover_photo' => 'service_covers/service_image.png',
                'category_id' => $categories->where('name.en', 'Chronic Disease Management')->first()->id
            ],
            [
                'name' => [
                    'en' => 'Home Nursing Care',
                    'ar' => 'رعاية تمريضية منزلية'
                ],
                'description' => [
                    'en' => 'Professional nursing care at home',
                    'ar' => 'رعاية تمريضية احترافية في المنزل'
                ],
                'price' => 150.00,
                'provider_type' => 'individual',
                'is_active' => true,
                'icon' => 'service_icons/service_icon.png',
                'cover_photo' => 'service_covers/service_image.png',
                'category_id' => $categories->where('name.en', 'Home Nursing Service')->first()->id
            ],
            [
                'name' => [
                    'en' => 'Doctor Home Visit',
                    'ar' => 'زيارة طبيب منزلية'
                ],
                'description' => [
                    'en' => 'Medical consultation at your home',
                    'ar' => 'استشارة طبية في منزلك'
                ],
                'price' => 250.00,
                'provider_type' => 'individual',
                'is_active' => true,
                'icon' => 'service_icons/service_icon.png',
                'cover_photo' => 'service_covers/service_image.png',
                'category_id' => $categories->where('name.en', 'Home Visit')->first()->id
            ],
            [
                'name' => [
                    'en' => 'Physiotherapy Session',
                    'ar' => 'جلسة علاج طبيعي'
                ],
                'description' => [
                    'en' => 'Professional physiotherapy at home',
                    'ar' => 'علاج طبيعي احترافي في المنزل'
                ],
                'price' => 180.00,
                'provider_type' => 'individual',
                'is_active' => true,
                'icon' => 'service_icons/service_icon.png',
                'cover_photo' => 'service_covers/service_image.png',
                'category_id' => $categories->where('name.en', 'Physiotherapy & Rehabilitation')->first()->id
            ],
            [
                'name' => [
                    'en' => 'Blood Test at Home',
                    'ar' => 'فحص دم في المنزل'
                ],
                'description' => [
                    'en' => 'Complete blood test collection at home',
                    'ar' => 'سحب عينات فحص دم كاملة في المنزل'
                ],
                'price' => 120.00,
                'provider_type' => 'individual',
                'is_active' => true,
                'icon' => 'service_icons/service_icon.png',
                'cover_photo' => 'service_covers/service_image.png',
                'category_id' => $categories->where('name.en', 'Schedule a Lab Test')->first()->id
            ]
        ];

        foreach ($services as $service) {
            Service::create(array_merge($service, [
                'added_by' => $admin->id
            ]));
        }
    }
}