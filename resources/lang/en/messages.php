<?php

return [
    'admin' => [
        'create_success' => 'Admin/moderator account created successfully.',
        'delete_success' => 'Admin/moderator account deleted successfully.',
        'deactivate_success' => 'Admin/moderator account deactivated successfully.',
        'document_approved' => 'Document approved successfully.',
        'document_rejected' => 'Document rejected successfully.',
        'required_document_added' => 'Required document added successfully.',
        'permission_denied' => 'You do not have permission to perform this action.',
        'invalid_user_type' => 'You can only manage admins or moderators.',
        'super_admin_restriction' => 'You cannot create super admins.',
        'admin_restriction' => 'You can only create moderators.',
    ],
    'auth' => [
        'register' => [
            'customer_success' => 'Customer registered successfully',
            'provider_success' => 'Provider registered successfully. Please upload required documents.',
            'validation_failed' => 'Validation failed during registration.',
            'failed' => 'Registration failed. Please try again.',
        ],
        'login' => [
            'validation_failed' => 'Validation failed during login.',
            'invalid_credentials' => 'The provided email or password is incorrect.',
            'type_mismatch' => 'You are not authorized to access this account type.',
            'under_review' => 'Your account is under review. Please check your documents.',
            'pending' => 'Your account is under review. Please check your documents.',
            'deactivated' => 'Your account is deactivated',
            'success' => 'Login successful',
        ],
        'logout' => [
            'no_token' => 'No token found.',
            'success' => 'Successfully logged out.',
            'failed' => 'An error occurred during logout.',
        ],
        'me' => [
            'unauthorized' => 'Unauthorized access. Please log in.',
            'success' => 'User retrieved successfully',
            'failed' => 'An error occurred.',
        ],
    ],
    'provider' => [
        'upload_success' => 'Document uploaded successfully. Waiting for admin approval.',
        'list_success' => 'Documents retrieved successfully',
        'status_success' => 'Document status retrieved successfully',
        'required_docs_success' => 'Remaining required documents retrieved successfully',
        'not_found' => 'Provider not found.',
        'document_not_required' => 'This document is not required for your provider type.',
    ],
    'password' => [
        'code_sent' => 'Reset code sent to your email.',
        'code_verified' => 'Code verified. Proceed to reset password.',
        'password_reset' => 'Password has been reset successfully.',
        'invalid_code' => 'Invalid or expired reset code.',
        'too_many_attempts' => 'Too many attempts. Please request a new code.',
        'inactive_account' => 'Your account is not active.',
    ],
    'user' => [
        'profile_updated' => 'Profile updated successfully',
        'location_updated' => 'Location updated successfully',
        'fcm_updated' => 'FCM token updated successfully',
    ],
    'category' => [
        'created' => 'Category created successfully',
        'updated' => 'Category updated successfully',
        'deleted' => 'Category deleted successfully',
        'status_changed' => 'Category status updated',
        'not_found' => 'Category not found',
        'fetch_failed' => 'Failed to fetch categories',
        'creation_failed' => 'Failed to create category',
        'update_failed' => 'Failed to update category',
        'delete_failed' => 'Failed to delete category',
        'status_change_failed' => 'Failed to change category status',
        'fetched' => 'Categories fetched successfully',
    ],

    'language' => [
        'updated' => 'Language preference updated successfully',
        'update_failed' => 'Failed to update language preference',
        'invalid' => 'Invalid language selection',
    ],
    
];