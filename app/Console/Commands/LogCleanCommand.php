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
        
        $this->info("Starting log cleanup (retaining logs from last {$hours} hours)...");
        Log::channel('scheduler')->info("Initiating log cleanup", [
            'retention_hours' => $hours,
            'cutoff_time' => $cutoff
        ]);

        $results = [
            'laravel.log' => $this->cleanLogFile('laravel.log', $cutoff),
            'notifications.log' => $this->cleanLogFile('notifications.log', $cutoff)
        ];
        
        $this->table(
            ['File', 'Status', 'Size', 'Last Modified'],
            $this->formatResults($results)
        );

        Log::channel('scheduler')->info('Log files cleanup completed', [
            'results' => $results
        ]);
    }

    protected function cleanLogFile($filename, $cutoff): array
    {
        $path = storage_path("logs/{$filename}");
        $result = [
            'file' => $filename,
            'action' => 'skipped',
            'reason' => 'not_found'
        ];
        
        if (!file_exists($path)) {
            return $result;
        }
        
        $fileSize = round(filesize($path)/1024, 2); // KB
        $modified = filemtime($path);
        $modifiedDate = date('Y-m-d H:i:s', $modified);
        
        $result = [
            'file' => $filename,
            'size_kb' => $fileSize,
            'last_modified' => $modifiedDate,
            'action' => 'retained',
            'reason' => 'within_retention'
        ];

        if ($modified < $cutoff->timestamp) {
            if (unlink($path)) {
                $result['action'] = 'deleted';
                $result['reason'] = 'expired';
                
                // Create new empty log file
                file_put_contents($path, '');
                chmod($path, 0644);
            } else {
                $result['action'] = 'failed';
                $result['reason'] = 'delete_failed';
            }
        }
        
        return $result;
    }

    protected function formatResults(array $results): array
    {
        return array_map(function ($item) {
            return [
                $item['file'],
                strtoupper($item['action']),
                $item['size_kb'] ?? 'N/A' . ' KB',
                $item['last_modified'] ?? 'N/A',
                $item['reason']
            ];
        }, $results);
    }
}