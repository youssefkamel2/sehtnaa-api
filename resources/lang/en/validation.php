<?php

return [
    'required' => 'The :attribute field is required.',
    'unique' => 'The :attribute has already been taken.',
    'numeric' => 'The :attribute must be a number.',
    'between' => 'The :attribute must be between :min and :max.',
    'image' => 'The :attribute must be an image.',
    'mimes' => 'The :attribute must be a file of type: :values.',
    'max' => [
        'numeric' => 'The :attribute may not be greater than :max.',
        'file' => 'The :attribute may not be greater than :max kilobytes.',
        'string' => 'The :attribute may not be greater than :max characters.',
    ],
    'in' => 'The selected :attribute is invalid.',
    'string' => 'The :attribute must be a string.',

    'custom' => [
        'phone' => [
            'unique' => 'This phone number is already in use.',
        ],
    ],

    'attributes' => [
        'first_name' => 'first name',
        'last_name' => 'last name',
        'phone' => 'phone number',
        'address' => 'address',
        'profile_image' => 'profile image',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'fcm_token' => 'FCM token',
        'device_type' => 'device type',
        'language' => 'language',
    ],
];