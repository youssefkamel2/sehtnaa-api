<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategoriesTableSeeder extends Seeder
{
    public function run()
    {
        $admin = User::where('email', 'superadmin@example.com')->first();

        $categories = [
            [
                'name' => [
                    'en' => 'Chronic Disease Management',
                    'ar' => 'إدارة الأمراض المزمنة'
                ],
                'description' => [
                    'en' => 'Comprehensive Home-Based Chronic Disease Management provides ongoing medical care...',
                    'ar' => 'نقدم خدمة متابعة الأمراض المزمنة في المنزل لضمان استقرار الحالة الصحية...'
                ],
                'icon' => 'category_icons/GlAD1SNooJtbQniuT4qj3p56JrVLGM6LZzBfrq4U.svg',
                'is_active' => true,
                'order' => 1,
            ],
            [
                'name' => [
                    'en' => 'Home Nursing Service',
                    'ar' => 'خدمات تمريضيه منزليه'
                ],
                'description' => [
                    'en' => 'A Home Nursing Service provides professional medical care and assistance...',
                    'ar' => 'تقدم خدمة التمريض المنزلي رعاية طبية احترافية للمرضى في منازلهم...'
                ],
                'icon' => 'category_icons/5zG9PFU622VkVVYBV1hozNBMTt1I4GZWCWFgEMzf.svg',
                'is_active' => true,
                'order' => 2,
            ],
            [
                'name' => [
                    'en' => 'Home Visit',
                    'ar' => 'زيار منزليه'
                ],
                'description' => [
                    'en' => 'make every medical things in home',
                    'ar' => 'كل الامور الطبيه في المنزل'
                ],
                'icon' => 'category_icons/e2BgzsKlM5POLxyijalXKA4XGP6XcKzEAv2Idwv5.svg',
                'is_active' => true,
                'order' => 3,
            ],
            [
                'name' => [
                    'en' => 'Physiotherapy & Rehabilitation',
                    'ar' => 'العلاج الطبيعي والتأهيل'
                ],
                'description' => [
                    'en' => 'Our Home Physiotherapy & Rehabilitation service provides expert care...',
                    'ar' => 'تقدم خدمة العلاج الطبيعي والتأهيل المنزلي رعاية متخصصة...'
                ],
                'icon' => 'category_icons/iWRnBg4XjR2VrJIdt5680CE64HNquUKq08imqSao.svg',
                'is_active' => true,
                'order' => 4,
            ],
            [
                'name' => [
                    'en' => 'Schedule a Lab Test',
                    'ar' => 'حجز فحص مخبري'
                ],
                'description' => [
                    'en' => 'Booking a lab test has never been easier! With our Home Lab Test Service...',
                    'ar' => 'لا داعي للذهاب إلى المختبر! مع خدمة الفحص المخبري المنزلي...'
                ],
                'icon' => 'category_icons/rdQAsXwNonk4qjpgqHYvDL5mO87mjNutOVAiBLmD.svg',
                'is_active' => true,
                'order' => 5,
            ]
        ];

        foreach ($categories as $category) {
            Category::create(array_merge($category, [
                'added_by' => $admin->id
            ]));
        }
    }
}