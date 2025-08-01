<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LogService;

class ProcessStuckJobs extends Command
{
    protected $signature = 'queue:process-stuck {--queue=notifications : Queue to process} {--jobs=10 : Number of jobs to process}';
    protected $description = 'Process stuck jobs in the queue';

    public function handle()
    {
        $queue = $this->option('queue');
        $jobs = $this->option('jobs');

        $this->info("Processing {$jobs} jobs from {$queue} queue...");

        try {
            // Process jobs
            $result = \Artisan::call('queue:work', [
                '--queue' => $queue,
                '--timeout' => 60,
                '--tries' => 3,
                '--max-jobs' => $jobs,
                '--stop-when-empty' => true
            ]);

            if ($result === 0) {
                $this->info("Successfully processed jobs from {$queue} queue");
                LogService::scheduler('info', 'Stuck jobs processed successfully', [
                    'queue' => $queue,
                    'jobs_processed' => $jobs
                ]);
            } else {
                $this->error("Failed to process jobs from {$queue} queue");
                LogService::scheduler('error', 'Failed to process stuck jobs', [
                    'queue' => $queue,
                    'exit_code' => $result
                ]);
            }

        } catch (\Exception $e) {
            $this->error("Error processing jobs: " . $e->getMessage());
            LogService::exception($e, [
                'action' => 'process_stuck_jobs',
                'queue' => $queue
            ]);
        }

        return 0;
    }
}