<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ResetPasswordController;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::get('/me', [AuthController::class, 'me'])->middleware(['auth:api']);
});

Route::prefix('reset-password')->group(function () {
    Route::post('/send-code', [ResetPasswordController::class, 'sendCode']);
    Route::post('/verify-code', [ResetPasswordController::class, 'verifyCode']);
    Route::post('/reset', [ResetPasswordController::class, 'resetPassword']);
});

// Provider 
Route::prefix('provider')->middleware('throttle:20,1')->group(function () {
    Route::post('/upload-document', [ProviderController::class, 'uploadDocument']);
    Route::post('/documents', [ProviderController::class, 'listDocuments']);
    Route::post('/document-status', [ProviderController::class, 'documentStatus']);
    Route::post('/required-documents', [ProviderController::class, 'getRequiredDocuments']);
    Route::post('/update-fcm-token', [ProviderController::class, 'updateFcmToken']);
    Route::post('/accept/{requestId}', [ProviderController::class, 'acceptRequest'])->middleware(['auth:api']);
    Route::post('/complete/{requestId}', [ProviderController::class, 'completeRequest'])->middleware(['auth:api']);
    Route::get('/feedbacks', [ProviderController::class, 'getProviderFeedbacks'])->middleware(['auth:api']);
    Route::get('/complaints', [ProviderController::class, 'getProviderComplaints'])->middleware(['auth:api']);
    Route::get('/analytics', [ProviderController::class, 'getProviderAnalytics'])->middleware(['auth:api']);
    Route::post('/availability', [ProviderController::class, 'setAvailability'])->middleware(['auth:api']);
});

Route::prefix('admin')->middleware(['auth:api', 'role:admin', 'check.status'])->group(function () {

    Route::get('/', [AdminController::class, 'index']);
    Route::post('/create-admin', [AdminController::class, 'createAdmin']);
    Route::post('/update-admin', [AdminController::class, 'updateAdmin']);
    Route::post('/delete-admin', [AdminController::class, 'deleteAdmin']);
    Route::post('/toggle-status', [AdminController::class, 'toggleStatus']);
    Route::prefix('complaints')->group(function () {
        Route::get('/', [ComplaintController::class, 'index']);
        Route::get('/{id}', [ComplaintController::class, 'show']);
        Route::put('/{id}/status', [ComplaintController::class, 'updateStatus']);
    });
    // dashboard
    Route::get('/dashboard', [DashboardController::class, 'dashboard']);
    // customers
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'getAllCustomers']);
        Route::post('/{id}/toggle-status', [CustomerController::class, 'toggleCustomerStatus']);
    });

    // requests
    Route::prefix('requests')->group(function () {
        Route::get('/', [RequestController::class, 'getAllRequests']);
    });

    // notifications
    Route::prefix('notifications')->group(function () {
        Route::get('campaigns', [UserController::class, 'getCampaigns']);
        Route::post('/campaigns', [UserController::class, 'sendNotificationCampaign']);
        // Route::get('/campaign/{campaignId}', [UserController::class, 'getCampaignStatus']);
    });

    // providers 
    Route::prefix('providers')->group(function () {
        Route::get('/', [ProviderController::class, 'getAllProviders']);
        Route::post('/change-status', [ProviderController::class, 'changeStatus']);
        Route::post('/approve-document', [ProviderController::class, 'approveDocument']);
        Route::post('/reject-document', [ProviderController::class, 'rejectDocument']);
        Route::post('/add-document', [ProviderController::class, 'addRequiredDocument']);
        // required documents
        Route::get('/required-documents', [ProviderController::class, 'getAllRequiredDocuments']);
        Route::post('/required-documents/{id}', [ProviderController::class, 'updateRequiredDocument']);
        Route::delete('/required-documents/{id}', [ProviderController::class, 'deleteRequiredDocument']);
    });

    // analytics

    Route::prefix('analytics')->group(function () {
        // Customers
    Route::get('/customers', [AnalyticsController::class, 'customerAnalytics']);
    Route::get('/customers-export', [AnalyticsController::class, 'exportCustomerAnalytics'])->name('admin.analytics.customers.export');
    
    // Services
    Route::get('/services', [AnalyticsController::class, 'serviceAnalytics']);
    Route::get('/services-export', [AnalyticsController::class, 'exportServiceAnalytics'])->name('admin.analytics.services.export');
    
    // Providers
    Route::get('/providers', [AnalyticsController::class, 'providerAnalytics']);
    Route::get('/providers-export', [AnalyticsController::class, 'exportProviderAnalytics'])->name('admin.analytics.providers.export');
    
    // Complaints
    Route::get('/complaints', [AnalyticsController::class, 'complaintAnalytics']);
    Route::get('/complaints-export', [AnalyticsController::class, 'exportComplaintAnalytics'])->name('admin.analytics.complaints.export');
    
    // Requests
    Route::get('/requests', [AnalyticsController::class, 'requestAnalytics']);
    Route::get('/requests-export', [AnalyticsController::class, 'exportRequestAnalytics'])->name('admin.analytics.requests.export');
    
    });
});


Route::prefix('user')->middleware(['auth:api'])->group(function () {

    Route::post('/update-profile', [UserController::class, 'updateProfile']);
    Route::post('/update-location', [UserController::class, 'updateLocation']);
    Route::post('/update-fcm-token', [UserController::class, 'updateFcmToken']);
    Route::post('/update-language', [UserController::class, 'updateLanguage']);
    Route::post('/update-profile-image', [UserController::class, 'updateProfileImage']);
    Route::post('/update-password', [UserController::class, 'changePassword']);
    Route::get('/ongoing-requests', [RequestController::class, 'getOngoingRequests']);
});


Route::prefix('categories')->middleware(['auth:api'])->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::post('/', [CategoryController::class, 'store'])->middleware(['role:admin', 'check.status']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    Route::post('/{id}', [CategoryController::class, 'update'])->middleware(['role:admin', 'check.status']);
    Route::delete('/{id}', [CategoryController::class, 'destroy'])->middleware(['role:admin', 'check.status']);
    Route::post('/{id}/toggle-status', [CategoryController::class, 'toggleStatus'])->middleware(['role:admin', 'check.status']);
    Route::get('/{id}/services', [CategoryController::class, 'getCategoryServices']);
});

Route::prefix('services')->middleware(['auth:api'])->group(function () {
    Route::get('/', [ServiceController::class, 'index'])->middleware(['role:admin', 'check.status']);
    Route::post('/', [ServiceController::class, 'store'])->middleware(['role:admin', 'check.status']);
    Route::get('/{id}', [ServiceController::class, 'show']);
    Route::post('/{id}', [ServiceController::class, 'update'])->middleware(['role:admin', 'check.status']);
    Route::delete('/{id}', [ServiceController::class, 'destroy'])->middleware(['role:admin', 'check.status']);
    Route::post('/{id}/toggle-status', [ServiceController::class, 'toggleStatus'])->middleware(['role:admin', 'check.status']);
    Route::post('/{id}/requirements', [ServiceController::class, 'addRequirement'])->middleware(['role:admin', 'check.status']);
    Route::post('/requirements/{id}', [ServiceController::class, 'updateRequirement'])->middleware(['role:admin', 'check.status']);
    Route::delete('/requirements/{id}', [ServiceController::class, 'deleteRequirement'])->middleware(['role:admin', 'check.status']);
});

// requests
Route::prefix('requests')->middleware(['auth:api'])->group(function () {
    Route::get('/', [RequestController::class, 'getUserRequests']);
    Route::post('/', [RequestController::class, 'createRequest']);
    Route::get('/{id}', [RequestController::class, 'getRequestDetails']);
    Route::post('/{id}/cancel', [RequestController::class, 'cancelRequest']);
    Route::post('/{id}/feedback', [RequestController::class, 'submitFeedback']);
    Route::post('/{id}/complaint', [RequestController::class, 'createComplaint']);
    Route::get('/{id}/complaints', [RequestController::class, 'getRequestComplaints']);
    Route::post('/{id}/add-service', [RequestController::class, 'addServiceToRequest']);
});


// landing page
Route::prefix('landing')->group(function () {
    Route::get('/', [DashboardController::class, 'landing']);
    Route::get('/categories', [DashboardController::class, 'getCategoriesWithServices']);
    Route::get('/categories/{id}', [DashboardController::class, 'getCategoryWithServices']);
    Route::get('/services/{id}', [DashboardController::class, 'getServiceById']);


});



