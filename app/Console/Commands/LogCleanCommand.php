<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LogCleanCommand extends Command
{
    protected $signature = 'log:clean {--keep-last=48 : Hours to keep logs}';
    protected $description = 'Clean up log files older than specified hours';

    public function handle()
    {
        $hours = (int) $this->option('keep-last');
        $cutoff = now()->subHours($hours);
        
        $this->info("Starting log cleanup (retaining logs from last {$hours} hours)...");
        Log::channel('scheduler')->info("Initiating log cleanup for files older than {$cutoff}");

        $this->cleanLogFile('laravel.log', $cutoff);
        $this->cleanLogFile('notifications.log', $cutoff);
        
        $this->info('Log files cleanup completed successfully.');
        Log::channel('scheduler')->info('Log files cleanup process completed');
    }

    protected function cleanLogFile($filename, $cutoff)
    {
        $path = storage_path("logs/{$filename}");
        
        if (!file_exists($path)) {
            $message = "Log file {$filename} does not exist - nothing to clean";
            $this->warn($message);
            Log::channel('scheduler')->info($message);
            return;
        }
        
        $fileSize = round(filesize($path)/1024, 2); // KB
        $modified = filemtime($path);
        $modifiedDate = date('Y-m-d H:i:s', $modified);

        if ($modified < $cutoff->timestamp) {
            $details = "{$filename} (Size: {$fileSize}KB, Modified: {$modifiedDate})";
            
            if (unlink($path)) {
                $message = "Deleted old log file: {$details}";
                $this->info($message);
                Log::channel('scheduler')->info($message);
                
                // Create new empty log file
                file_put_contents($path, '');
                chmod($path, 0644);
                Log::channel('scheduler')->info("Recreated empty {$filename}");
            } else {
                $message = "Failed to delete log file: {$details}";
                $this->error($message);
                Log::channel('scheduler')->error($message);
            }
        } else {
            $message = "Retained {$filename} (Size: {$fileSize}KB, Modified: {$modifiedDate}) - within retention period";
            $this->info($message);
            Log::channel('scheduler')->info($message);
        }
    }
}