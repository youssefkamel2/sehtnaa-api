<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ProviderController;
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

// Provider routes
Route::prefix('provider')->middleware('throttle:20,1')->group(function () {
    Route::post('/upload-document', [ProviderController::class, 'uploadDocument']);
    Route::get('/documents', [ProviderController::class, 'listDocuments']);
    Route::get('/document-status', [ProviderController::class, 'documentStatus']);
    Route::get('/required-documents', [ProviderController::class, 'getRequiredDocuments']);
});

Route::prefix('admin')->middleware(['auth:api', 'role:admin'])->group(function () {
    Route::post('/create-admin', [AdminController::class, 'createAdmin']);
    Route::post('/delete-admin', [AdminController::class, 'deleteAdmin']);
    Route::post('/deactivate-admin', [AdminController::class, 'deactivateAdmin']);
    Route::post('/approve-document', [AdminController::class, 'approveDocument']);
    Route::post('/reject-document', [AdminController::class, 'rejectDocument']);
});
