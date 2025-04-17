<?php

namespace App\Console;

use Illuminate\Support\Facades\Log;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // Process queue jobs
        $schedule->command('queue:work', [
            '--queue' => 'notifications,default',
            '--tries' => 3,
            '--stop-when-empty' => true,
            '--sleep' => 3,
            '--timeout' => 60
        ])
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/queue-worker.log'))
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Queue processed successfully');
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Queue processing failed');
            });

        // Retry failed jobs daily
        $schedule->command('queue:retry all')
            ->daily()
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Failed jobs retry completed');
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Failed jobs retry failed');
            });

        // Prune Telescope entries
        $schedule->command('telescope:prune')
            ->hourly()
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Telescope pruning completed successfully.');
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Telescope pruning failed.');
            });

        // Prune Activity Log entries
        $schedule->command('activitylog:clean')
            ->hourly()
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Activity Log pruning completed successfully.');
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Activity Log pruning failed.');
            });

        // Monitor queue health
        $schedule->command('queue:monitor')
            ->everyFiveMinutes()
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Queue health check completed');
            });

        // Clean log files (laravel.log and notifications.log) older than 48 hours
        $schedule->command('log:clean --keep-last=48')
            ->daily()
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Log files cleanup completed successfully.');
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Log files cleanup failed.');
            });
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
