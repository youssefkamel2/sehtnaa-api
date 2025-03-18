<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTelescopeLogs extends Command
{
    protected $signature = 'telescope:prune';
    protected $description = 'Prune Telescope logs older than 48 hours';

    public function handle()
    {
        $cutoff = now()->subHours(48);
        DB::table('telescope_entries')->where('created_at', '<', $cutoff)->delete();
        $this->info('Telescope logs pruned successfully.');
    }
}
