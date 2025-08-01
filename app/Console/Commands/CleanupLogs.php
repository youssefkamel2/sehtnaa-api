<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Services\LogService;

class CleanupLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:cleanup {--days=30 : Number of days to keep logs} {--force : Force cleanup without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old log files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->option('days');
        $force = $this->option('force');

        $logPath = storage_path('logs');
        $cutoffDate = now()->subDays($days);

        $this->info("Cleaning up log files older than {$days} days...");

        if (!$force && !$this->confirm('This will permanently delete old log files. Continue?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $deletedFiles = 0;
        $deletedSize = 0;

        if (File::exists($logPath)) {
            $files = File::files($logPath);

            foreach ($files as $file) {
                $filePath = $file->getPathname();
                $fileName = $file->getFilename();

                // Skip .gitignore and other non-log files
                if (in_array($fileName, ['.gitignore', '.gitkeep'])) {
                    continue;
                }

                $fileTime = File::lastModified($filePath);
                $fileDate = \Carbon\Carbon::createFromTimestamp($fileTime);

                if ($fileDate->lt($cutoffDate)) {
                    $fileSize = File::size($filePath);
                    File::delete($filePath);

                    $deletedFiles++;
                    $deletedSize += $fileSize;

                    $this->line("Deleted: {$fileName} ({$this->formatBytes($fileSize)})");
                }
            }
        }

        $this->info("Cleanup completed. Deleted {$deletedFiles} files ({$this->formatBytes($deletedSize)})");

        LogService::scheduler('info', 'Log cleanup completed', [
            'deleted_files' => $deletedFiles,
            'deleted_size' => $deletedSize,
            'cutoff_days' => $days
        ]);

        return 0;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}