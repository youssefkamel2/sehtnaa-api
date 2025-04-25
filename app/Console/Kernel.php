<?php

namespace App\Console;

use Illuminate\Support\Facades\Log;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {

        // Add the request expansion queue worker
        $schedule->command('queue:work --queue=request_expansion --tries=3 --sleep=3 --timeout=60 --stop-when-empty')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/request_expansion.log'))
            ->onSuccess(function () {
                $output = file_get_contents(storage_path('logs/request_expansion.log'));
                $processedJobs = substr_count($output, 'Processed:');
                $failedJobs = substr_count($output, 'Failed:');

                Log::channel('scheduler')->info("Request expansion queue processed", [
                    'jobs_processed' => $processedJobs,
                    'jobs_failed' => $failedJobs,
                    'output' => $output
                ]);

                file_put_contents(storage_path('logs/request_expansion.log'), '');
            })
            ->onFailure(function () {
                $error = file_get_contents(storage_path('logs/request_expansion.log'));
                Log::channel('scheduler')->error('Request expansion queue processing failed', [
                    'error' => $error
                ]);
            });

        // Process queue jobs with detailed logging
        $schedule->command('queue:work --queue=notifications,default --tries=3 --sleep=3 --timeout=60 --stop-when-empty')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/queue-worker.log'))
            ->onSuccess(function () {
                $output = file_get_contents(storage_path('logs/queue-worker.log'));
                $processedJobs = substr_count($output, 'Processed:');
                $failedJobs = substr_count($output, 'Failed:');

                Log::channel('scheduler')->info("Queue processed successfully", [
                    'jobs_processed' => $processedJobs,
                    'jobs_failed' => $failedJobs,
                    'output' => $output
                ]);

                file_put_contents(storage_path('logs/queue-worker.log'), '');
            })
            ->onFailure(function () {
                $error = file_get_contents(storage_path('logs/queue-worker.log'));
                Log::channel('scheduler')->error('Queue processing failed', [
                    'error' => $error
                ]);
            });

        // Retry failed jobs daily with counts
        $schedule->command('queue:retry all')
            ->daily()
            ->onSuccess(function () {
                $count = DB::table('failed_jobs')->count();
                Log::channel('scheduler')->info('Failed jobs retry completed', [
                    'jobs_retried' => $count,
                    'remaining_failed_jobs' => 0
                ]);
            })
            ->onFailure(function () {
                $count = DB::table('failed_jobs')->count();
                Log::channel('scheduler')->error('Failed jobs retry failed', [
                    'remaining_failed_jobs' => $count
                ]);
            });

        // Prune Telescope entries with count
        $schedule->command('telescope:prune')
            ->hourly()
            ->onSuccess(function () {
                $countBefore = DB::table('telescope_entries')->count();
                $countAfter = DB::table('telescope_entries')
                    ->where('created_at', '>', now()->subHours(48))
                    ->count();

                Log::channel('scheduler')->info('Telescope pruning completed', [
                    'entries_pruned' => $countBefore - $countAfter,
                    'entries_remaining' => $countAfter
                ]);
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Telescope pruning failed');
            });

        // Prune Activity Log entries with count
        $schedule->command('activitylog:clean')
            ->hourly()
            ->onSuccess(function () {
                $count = DB::table('activity_log')
                    ->where('created_at', '<', now()->subDays(7))
                    ->count();

                Log::channel('scheduler')->info('Activity Log pruning completed', [
                    'entries_cleaned' => $count
                ]);
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Activity Log pruning failed');
            });

        // Enhanced log cleanup with file details
        $schedule->command('log:clean --keep-last=48')
            ->daily()
            ->onSuccess(function () {
                $files = [
                    'laravel.log' => $this->getLogFileInfo('laravel.log'),
                    'notifications.log' => $this->getLogFileInfo('notifications.log')
                ];

                Log::channel('scheduler')->info('Log files cleanup completed', [
                    'files_processed' => $files,
                    'retention_period' => '48 hours'
                ]);
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Log files cleanup failed');
            });
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }

    // Helper methods

    protected function getLastProcessedJob(string $output): ?string
    {
        preg_match_all('/Processed: (.+)/', $output, $matches);
        return end($matches[1]) ?: null;
    }

    protected function getExecutionTime(string $output): ?string
    {
        preg_match('/Memory usage: .+ \(([0-9.]+) ms\)/', $output, $matches);
        return $matches[1] ?? null;
    }

    protected function getRedisQueueSize(): int
    {
        try {
            return app('redis')->llen(config('queue.connections.redis.queue'));
        } catch (\Exception $e) {
            return -1; // indicates error
        }
    }

    protected function getLogFileInfo(string $filename): array
    {
        $path = storage_path("logs/{$filename}");

        if (!file_exists($path)) {
            return ['status' => 'not_found'];
        }

        return [
            'size' => round(filesize($path) / 1024 . ' KB'),
            'last_modified' => date('Y-m-d H:i:s', filemtime($path)),
            'status' => 'exists'
        ];
    }
}
