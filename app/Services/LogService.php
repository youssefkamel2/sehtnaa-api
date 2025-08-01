<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class LogService
{
    /**
     * Log authentication events
     */
    public static function auth($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('auth')->$level($message, $context);
    }

    /**
     * Log API requests and responses
     */
    public static function api($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('api')->$level($message, $context);
    }

    /**
     * Log database operations
     */
    public static function database($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('database')->$level($message, $context);
    }

    /**
     * Log job processing
     */
    public static function jobs($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('jobs')->$level($message, $context);
    }

    /**
     * Log notifications and FCM
     */
    public static function notifications($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('notifications')->$level($message, $context);
    }

    /**
     * Log FCM errors only
     */
    public static function fcmErrors($message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('fcm_errors')->error($message, $context);
    }

    /**
     * Log Firestore operations
     */
    public static function firestore($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('firestore')->$level($message, $context);
    }

    /**
     * Log provider operations
     */
    public static function providers($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('providers')->$level($message, $context);
    }

    /**
     * Log request operations
     */
    public static function requests($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('requests')->$level($message, $context);
    }

    /**
     * Log scheduler operations
     */
    public static function scheduler($level, $message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('scheduler')->$level($message, $context);
    }

    /**
     * Log debug information (only in development)
     */
    public static function debug($message, array $context = [])
    {
        if (Config::get('app.debug')) {
            $context = self::sanitizeContext($context);
            Log::channel('debug')->debug($message, $context);
        }
    }

    /**
     * Log errors (production safe)
     */
    public static function error($message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::channel('errors')->error($message, $context);

        // Also log to main channel for visibility
        Log::error($message, $context);
    }

    /**
     * Log warnings
     */
    public static function warning($message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::warning($message, $context);
    }

    /**
     * Log info messages
     */
    public static function info($message, array $context = [])
    {
        $context = self::sanitizeContext($context);
        Log::info($message, $context);
    }

    /**
     * Sanitize context data for production safety
     */
    private static function sanitizeContext(array $context): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'token',
            'secret',
            'key',
            'authorization',
            'cookie',
            'session',
            'credit_card',
            'ssn',
            'phone',
            'email',
            'address',
            'ip',
            'user_agent'
        ];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::sanitizeContext($value);
            } elseif (is_string($value) && in_array(strtolower($key), $sensitiveKeys)) {
                $context[$key] = '[REDACTED]';
            }
        }

        return $context;
    }

    /**
     * Log exception safely for production
     */
    public static function exception(\Throwable $exception, array $context = [])
    {
        $message = $exception->getMessage();

        // In production, don't expose sensitive error details
        if (!Config::get('app.debug')) {
            $message = 'An error occurred';
            $context['exception_class'] = get_class($exception);
            $context['exception_code'] = $exception->getCode();
            $context['file'] = basename($exception->getFile());
            $context['line'] = $exception->getLine();
        } else {
            $context['trace'] = $exception->getTraceAsString();
        }

        self::error($message, $context);
    }
}