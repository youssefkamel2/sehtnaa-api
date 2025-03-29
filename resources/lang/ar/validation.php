<?php

return [
    'required' => 'حقل :attribute مطلوب.',
    'unique' => 'قيمة :attribute مُستخدمة من قبل.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'between' => 'يجب أن يكون :attribute بين :min و :max.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'mimes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'max' => [
        'numeric' => 'يجب ألا يتجاوز :attribute :max.',
        'file' => 'يجب ألا يتجاوز :attribute :max كيلوبايت.',
        'string' => 'يجب ألا يتجاوز :attribute :max حرفًا.',
    ],
    'in' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'string' => 'يجب أن يكون :attribute نصًا.',

    'custom' => [
        'phone' => [
            'unique' => 'رقم الهاتف هذا مستخدم بالفعل.',
        ],
    ],

    'attributes' => [
        'first_name' => 'الاسم الأول',
        'last_name' => 'الاسم الأخير',
        'phone' => 'رقم الهاتف',
        'address' => 'العنوان',
        'profile_image' => 'صورة الملف الشخصي',
        'latitude' => 'خط العرض',
        'longitude' => 'خط الطول',
        'fcm_token' => 'رمز FCM',
        'device_type' => 'نوع الجهاز',
        'language' => 'اللغة',
    ],
];