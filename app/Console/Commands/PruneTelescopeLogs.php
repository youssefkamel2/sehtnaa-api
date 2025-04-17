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
        $this->info("Starting Telescope logs pruning...");
        Log::channel('scheduler')->info("Initiating Telescope logs pruning");

        $cutoff = now()->subHours(48);
        $countBefore = DB::table('telescope_entries')->count();
        
        $deletedRows = DB::table('telescope_entries')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $countAfter = DB::table('telescope_entries')->count();
        
        $message = sprintf(
            "Pruned %d Telescope entries (Before: %d, After: %d, Cutoff: %s)",
            $deletedRows,
            $countBefore,
            $countAfter,
            $cutoff->toDateTimeString()
        );

        Log::channel('scheduler')->info($message);
        $this->info($message);
    }
}