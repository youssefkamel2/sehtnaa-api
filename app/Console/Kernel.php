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
        $schedule->command('queue:work --stop-when-empty --tries=3')
            ->everyMinute()
            ->withoutOverlapping()
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
            ->hourly()
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Queue health check completed');
            });
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}