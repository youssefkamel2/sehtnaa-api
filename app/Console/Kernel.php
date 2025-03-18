<?php

namespace App\Console;

use Illuminate\Support\Facades\Log;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        // Log the start of the scheduler
        $schedule->call(function () {
            Log::channel('scheduler')->info('Scheduler started at: ' . now());
        })->hourly();  // Run every hour
    
        // Log a message every hour
        $schedule->call(function () {
            Log::channel('scheduler')->info('Scheduler is running!');
        })->hourly();  // Run every hour
    
        // Prune Telescope entries older than 48 hours
        $schedule->command('telescope:prune')
            ->hourly()  // Run every hour
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Telescope pruning completed successfully.');
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Telescope pruning failed.');
            });
    
        // Prune Activity Log entries older than 48 hours
        $schedule->command('activitylog:clean')
            ->hourly()  // Run every hour
            ->onSuccess(function () {
                Log::channel('scheduler')->info('Activity Log pruning completed successfully.');
            })
            ->onFailure(function () {
                Log::channel('scheduler')->error('Activity Log pruning failed.');
            });
    
        // Log the end of the scheduler
        $schedule->call(function () {
            Log::channel('scheduler')->info('Scheduler finished at: ' . now());
        })->hourly();  // Run every hour
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}