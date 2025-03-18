<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneTelescopeLogs extends Command
{
    protected $signature = 'telescope:prune';
    protected $description = 'Prune Telescope logs older than 48 hours';

    public function handle()
    {
        // Delete logs older than 48 hours
        $cutoff = now()->subHours(48);
        $deletedRows = DB::table('telescope_entries')->where('created_at', '<', $cutoff)->delete();

        // Log the result
        Log::channel('scheduler')->info("Pruned $deletedRows Telescope logs older than 48 hours.");
        $this->info("Pruned $deletedRows Telescope logs older than 48 hours.");
    }
}