<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\PasswordResetCode;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use App\Notifications\ResetCodeNotification;
use Spatie\Activitylog\Models\Activity;
use App\Services\LogService;

class ResetPasswordController extends Controller
{
    use ResponseTrait;

    // Send reset password code
    public function sendCode(Request $request)
    {
        // Rate limiting: 5 attempts per minute
        if ($this->hasTooManyAttempts($request, 'send_reset_code')) {
            LogService::auth('warning', 'Rate limit exceeded for sending reset code', [
                'ip' => $request->ip()
            ]);
            return $this->error('Too many attempts. Please try again later.', 429);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            // Log validation failure
            activity()
                ->withProperties(['errors' => $validator->errors()])
                ->log('Validation failed during reset code request.');
            return $this->error($validator->errors()->first(), 422);
        }

        // Fetch the user
        $user = User::where('email', $request->email)->first();

        // Check if the user is active
        if ($user->status !== 'active') {
            // Log inactive account attempt
            activity()
                ->performedOn($user)
                ->withProperties(['email' => $request->email])
                ->log('Inactive account attempted to request reset code.');
            return $this->error('Your account is not active.', 403);
        }

        // Generate a random 6-digit numeric code
        $code = random_int(100000, 999999);

        // Hash the code for storage
        $hashedCode = Hash::make($code);

        // Store code in the database
        PasswordResetCode::updateOrCreate(
            ['email' => $request->email],
            [
                'reset_code' => $hashedCode,
                'expires_at' => now()->addMinutes(15),
                'attempts' => 0,
            ]
        );

        // Send the code via email (queued)
        $user->notify((new ResetCodeNotification($code))->onQueue('emails'));

        // Increment rate limiter
        RateLimiter::hit('send-reset-code:' . $request->ip());

        // Log successful reset code request
        activity()
            ->performedOn($user)
            ->withProperties(['email' => $request->email])
            ->log('Reset code sent successfully.');

        return $this->success(null, 'Reset code sent to your email.');
    }

    // Verify reset password code
    public function verifyCode(Request $request)
    {
        // Rate limiting: 5 attempts per minute
        if ($this->hasTooManyAttempts($request, 'verify_reset_code')) {
            LogService::auth('warning', 'Rate limit exceeded for verifying reset code', [
                'ip' => $request->ip()
            ]);
            return $this->error('Too many attempts. Please try again later.', 429);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:password_reset_codes,email',
            'reset_code' => 'required|numeric|digits:6',
        ]);

        if ($validator->fails()) {
            // Log validation failure
            activity()
                ->withProperties(['errors' => $validator->errors()])
                ->log('Validation failed during reset code verification.');
            return $this->error($validator->errors()->first(), 422);
        }

        $resetCode = PasswordResetCode::where('email', $request->email)->first();

        // Check if reset code exists and has not expired
        if (!$resetCode || $resetCode->expires_at < now()) {
            // Log invalid or expired reset code attempt
            activity()
                ->withProperties(['email' => $request->email])
                ->log('Invalid or expired reset code attempt.');
            return $this->error('Invalid or expired reset code.', 400);
        }

        // Verify the code
        if (!Hash::check($request->reset_code, $resetCode->reset_code)) {
            $resetCode->increment('attempts');

            // Check if attempts exceeded limit
            if ($resetCode->attempts >= 5) {
                $resetCode->delete(); // Invalidate the reset code

                // Log too many failed attempts
                activity()
                    ->withProperties(['email' => $request->email])
                    ->log('Too many failed reset code attempts. Code invalidated.');
                return $this->error('Too many attempts. Please request a new code.', 429);
            }

            // Log failed reset code attempt
            activity()
                ->withProperties(['email' => $request->email, 'ip' => $request->ip()])
                ->log('Failed reset code attempt.');

            // Increment rate limiter
            RateLimiter::hit('verify-reset-code:' . $request->ip());

            return $this->error('Invalid reset code.', 400);
        }

        // Mark code as used
        $resetCode->delete();

        // Log successful reset code verification
        activity()
            ->withProperties(['email' => $request->email])
            ->log('Reset code verified successfully.');

        return $this->success(null, 'Code verified. Proceed to reset password.');
    }

    // Reset password
    public function resetPassword(Request $request)
    {
        // Rate limiting: 5 attempts per minute
        if ($this->hasTooManyAttempts($request, 'reset_password')) {
            LogService::auth('warning', 'Rate limit exceeded for resetting password', [
                'ip' => $request->ip()
            ]);
            return $this->error('Too many attempts. Please try again later.', 429);
        }

        // Validate the input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:9|confirmed',
            'password_confirmation' => 'required|string|min:9',
        ]);

        // Return validation errors, if any
        if ($validator->fails()) {
            // Log validation failure
            activity()
                ->withProperties(['errors' => $validator->errors()])
                ->log('Validation failed during password reset.');
            return $this->error($validator->errors()->first(), 422);
        }

        // Fetch the user by email
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Log user not found
            activity()
                ->withProperties(['email' => $request->email])
                ->log('User not found during password reset.');
            return $this->error('User not found.', 404);
        }

        // Check if the user is active
        if ($user->status !== 'active') {
            // Log inactive account attempt
            activity()
                ->performedOn($user)
                ->withProperties(['email' => $request->email])
                ->log('Inactive account attempted to reset password.');
            return $this->error('Your account is not active.', 403);
        }

        // Update the password
        $user->password = Hash::make($request->password);
        $user->save();

        // Invalidate any existing reset codes
        PasswordResetCode::where('email', $request->email)->delete();

        // Log successful password reset
        activity()
            ->performedOn($user)
            ->withProperties(['email' => $request->email])
            ->log('Password reset successfully.');

        return $this->success(null, 'Password has been reset successfully.');
    }
}