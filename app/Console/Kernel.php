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
        // Process request expansion queue
        $schedule->call(function () {
            try {
                \Artisan::call('queue:work', ['--queue' => 'request-expansion', '--timeout' => 60, '--tries' => 3]);
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

        // Process general queue
        $schedule->call(function () {
            try {
                \Artisan::call('queue:work', ['--timeout' => 60, '--tries' => 3]);
                LogService::scheduler('info', 'Queue processed successfully', [
                    'status' => 'completed'
                ]);
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'queue_processing'
                ]);
            }
        })->everyMinute()->name('general-queue')->withoutOverlapping();

        // Retry failed jobs
        $schedule->call(function () {
            try {
                \Artisan::call('queue:retry', ['all' => true]);
                LogService::scheduler('info', 'Failed jobs retry completed');
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'failed_jobs_retry'
                ]);
            }
        })->hourly()->name('retry-failed-jobs');

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
