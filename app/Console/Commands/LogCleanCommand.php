<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LogCleanCommand extends Command
{
    protected $signature = 'log:clean {--keep-last=48 : Hours to keep logs}';
    protected $description = 'Clean up log files older than specified hours';

    public function handle()
    {
        $hours = (int) $this->option('keep-last');
        $cutoff = now()->subHours($hours);
        
        $this->cleanLogFile('laravel.log', $cutoff);
        $this->cleanLogFile('notifications.log', $cutoff);
        
        $this->info('Log files cleanup completed successfully.');
        Log::channel('scheduler')->info('Log files cleanup completed');
    }

    protected function cleanLogFile($filename, $cutoff)
    {
        $path = storage_path("logs/{$filename}");
        
        if (!file_exists($path)) {
            $this->warn("Log file {$filename} does not exist.");
            return;
        }
        
        $modified = filemtime($path);
        if ($modified < $cutoff->timestamp) {
            if (unlink($path)) {
                $this->info("Deleted old log file: {$filename}");
                Log::channel('scheduler')->info("Deleted old log file: {$filename}");
                
                // Create new empty log file
                file_put_contents($path, '');
                chmod($path, 0644);
            } else {
                $this->error("Failed to delete log file: {$filename}");
                Log::channel('scheduler')->error("Failed to delete log file: {$filename}");
            }
        } else {
            $this->info("Log file {$filename} is within the retention period.");
        }
    }
}