<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LogService;

class StartQueueWorkers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:start-workers {--daemon : Run workers in daemon mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start queue workers for notifications and default queues';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting queue workers...');

        try {
            // Start notifications queue worker
            $this->info('Starting notifications queue worker...');
            $this->call('queue:work', [
                '--queue' => 'notifications',
                '--timeout' => 60,
                '--tries' => 3,
                '--max-jobs' => 20,
                '--daemon' => $this->option('daemon')
            ]);

            LogService::jobs('info', 'Queue workers started manually', [
                'action' => 'manual_queue_worker_start'
            ]);

        } catch (\Exception $e) {
            LogService::exception($e, [
                'action' => 'manual_queue_worker_start'
            ]);

            $this->error('Failed to start queue workers: ' . $e->getMessage());
            return 1;
        }

        $this->info('Queue workers started successfully!');
        return 0;
    }
}