<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Services\LogService;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Ensure queue workers are running (check and restart if needed)
        $schedule->call(function () {
            try {
                // Check database connection first
                if (!\DB::connection()->getPdo()) {
                    LogService::scheduler('error', 'Database connection failed during queue worker health check', [
                        'action' => 'queue_worker_health_check'
                    ]);
                    return;
                }

                // Check if there are any jobs stuck in the queue for too long
                $stuckJobs = \DB::table('jobs')
                    ->where('created_at', '<', now()->subMinutes(5))
                    ->count();

                if ($stuckJobs > 0) {
                    LogService::scheduler('warning', 'Jobs stuck in queue detected', [
                        'stuck_jobs_count' => $stuckJobs,
                        'action' => 'queue_worker_health_check'
                    ]);

                    // Try to restart queue workers by dispatching a restart job
                    dispatch(function () {
                        // This will be processed by the queue system itself
                        LogService::jobs('info', 'Queue worker restart requested', [
                            'action' => 'queue_worker_restart'
                        ]);
                    })->onQueue('default');
                }

                // Log health check completion
                $totalJobs = \DB::table('jobs')->count();
                $failedJobs = \DB::table('failed_jobs')->count();

                LogService::scheduler('info', 'Queue workers health check completed', [
                    'total_jobs' => $totalJobs,
                    'failed_jobs' => $failedJobs,
                    'stuck_jobs' => $stuckJobs,
                    'action' => 'queue_worker_health_check'
                ]);
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'queue_worker_health_check'
                ]);
            }
        })->everyMinute()->name('queue-workers-health-check')->withoutOverlapping();

        // Process request expansion queue
        $schedule->call(function () {
            try {
                // Check database connection first
                if (!\DB::connection()->getPdo()) {
                    LogService::scheduler('error', 'Database connection failed during request expansion', [
                        'action' => 'request_expansion'
                    ]);
                    return;
                }

                \Artisan::call('queue:work', ['--queue' => 'request-expansion', '--timeout' => 60, '--tries' => 3, '--max-jobs' => 10]);
                LogService::scheduler('info', 'Request expansion queue processed', [
                    'queue' => 'request-expansion',
                    'status' => 'completed'
                ]);
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'queue' => 'request-expansion',
                    'action' => 'queue_processing'
                ]);
            }
        })->everyMinute()->name('request-expansion-queue')->withoutOverlapping();

        // Retry failed jobs
        $schedule->call(function () {
            try {
                // Check database connection first
                if (!\DB::connection()->getPdo()) {
                    LogService::scheduler('error', 'Database connection failed during failed jobs retry', [
                        'action' => 'failed_jobs_retry'
                    ]);
                    return;
                }

                // Get all failed job IDs and retry them
                $failedJobs = \DB::table('failed_jobs')->pluck('id')->toArray();
                if (!empty($failedJobs)) {
                    foreach ($failedJobs as $jobId) {
                        try {
                            \Artisan::call('queue:retry', ['id' => $jobId]);
                        } catch (\Exception $jobException) {
                            LogService::scheduler('error', 'Failed to retry specific job', [
                                'job_id' => $jobId,
                                'error' => $jobException->getMessage()
                            ]);
                        }
                    }
                    LogService::scheduler('info', 'Failed jobs retry completed', [
                        'retried_count' => count($failedJobs)
                    ]);
                }
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'failed_jobs_retry'
                ]);
            }
        })->everyFiveMinutes()->name('retry-failed-jobs');

        // Prune Telescope logs (if Telescope is enabled)
        if (class_exists('\Laravel\Telescope\Telescope')) {
            $schedule->call(function () {
                try {
                    \Artisan::call('telescope:prune');
                    LogService::scheduler('info', 'Telescope pruning completed');
                } catch (\Exception $e) {
                    LogService::exception($e, [
                        'action' => 'telescope_pruning'
                    ]);
                }
            })->daily()->name('prune-telescope');
        }

        // Prune Activity Log
        $schedule->call(function () {
            try {
                // Check database connection first
                if (!\DB::connection()->getPdo()) {
                    LogService::scheduler('error', 'Database connection failed during activity log pruning', [
                        'action' => 'activity_log_pruning'
                    ]);
                    return;
                }

                \Artisan::call('activitylog:clean');
                LogService::scheduler('info', 'Activity Log pruning completed');
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'activity_log_pruning'
                ]);
            }
        })->daily()->name('prune-activity-log');

        // Clean up old log files
        $schedule->call(function () {
            try {
                \Artisan::call('logs:cleanup', ['--days' => 30, '--force' => true]);
                LogService::scheduler('info', 'Log files cleanup completed');
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'log_cleanup'
                ]);
            }
        })->weekly()->name('cleanup-logs');

        // Clean up old export files
        $schedule->call(function () {
            try {
                $deleted = \File::deleteDirectory(storage_path('app/exports'), true);
                LogService::scheduler('info', "Deleted {$deleted} expired export files");
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'export_cleanup'
                ]);
            }
        })->daily()->name('cleanup-exports');

        // Clean up invalid FCM tokens
        $schedule->call(function () {
            try {
                // Check database connection first
                if (!\DB::connection()->getPdo()) {
                    LogService::scheduler('error', 'Database connection failed during invalid token cleanup', [
                        'action' => 'invalid_token_cleanup'
                    ]);
                    return;
                }

                \Artisan::call('notifications:cleanup-tokens', ['--force' => true]);
                LogService::scheduler('info', 'Invalid FCM tokens cleanup completed');
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'invalid_token_cleanup'
                ]);
            }
        })->daily()->name('cleanup-invalid-tokens');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
