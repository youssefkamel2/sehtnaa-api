<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\TestNotificationController;

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

// Provider routes
Route::prefix('provider')->middleware('throttle:20,1')->group(function () {
    Route::post('/upload-document', [ProviderController::class, 'uploadDocument']);
    Route::post('/documents', [ProviderController::class, 'listDocuments']);
    Route::post('/document-status', [ProviderController::class, 'documentStatus']);
    Route::post('/required-documents', [ProviderController::class, 'getRequiredDocuments']);
});

Route::prefix('admin')->middleware(['auth:api', 'role:admin'])->group(function () {
    Route::post('/create-admin', [AdminController::class, 'createAdmin']);
    Route::post('/delete-admin', [AdminController::class, 'deleteAdmin']);
    Route::post('/deactivate-admin', [AdminController::class, 'deactivateAdmin']);
    Route::post('/approve-document', [AdminController::class, 'approveDocument']);
    Route::post('/reject-document', [AdminController::class, 'rejectDocument']);
    Route::post('/add-document', [AdminController::class, 'addRequiredDocument']);
});


Route::prefix('user')->middleware(['auth:api'])->group(function () {

    Route::post('/update-profile', [UserController::class, 'updateProfile']);
    Route::post('/update-location', [UserController::class, 'updateLocation']);
    Route::post('/update-fcm-token', [UserController::class, 'updateFcmToken']);
    Route::post('/update-language', [UserController::class, 'updateLanguage']);
    Route::post('/update-profile-image', [UserController::class, 'updateProfileImage']);
    Route::post('/update-password', [UserController::class, 'changePassword']);

});


Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::post('/', [CategoryController::class, 'store'])->middleware(['auth:api', 'role:admin']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    Route::post('/{id}', [CategoryController::class, 'update'])->middleware(['auth:api', 'role:admin']);
    Route::delete('/{id}', [CategoryController::class, 'destroy'])->middleware(['auth:api', 'role:admin']);
    Route::post('/{id}/toggle-status', [CategoryController::class, 'toggleStatus'])->middleware(['auth:api', 'role:admin']);
});





Route::middleware('auth:api')->group(function () {
    Route::post('/send-test-notification', [TestNotificationController::class, 'sendTestNotification']);
});

