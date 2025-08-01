<?php

namespace App\Jobs;

use App\Models\Request as ServiceRequest;
use App\Services\ProviderNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\LogService;

class ExpandRequestSearchRadius implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $request;
    public $currentRadius;
    public $attempt;

    public function __construct(ServiceRequest $request, $currentRadius, $attempt = 1)
    {
        $this->request = $request->withoutRelations();
        $this->currentRadius = $currentRadius;
        $this->attempt = $attempt;
    }

    public function handle(ProviderNotifier $providerNotifier)
    {
        // Reload the fresh request data
        $request = ServiceRequest::find($this->request->id);

        // Check if request is still pending
        if (!$request || $request->status !== 'pending') {
            LogService::requests('info', 'Request no longer pending, stopping expansion', [
                'request_id' => $request->id,
                'status' => $request->status
            ]);
            return;
        }

        $nextRadius = $this->getNextRadius($this->currentRadius);

        if ($nextRadius) {
            // Find and notify providers for the next radius
            $notifiedCount = $providerNotifier->findAndNotifyProviders($request, $nextRadius);

            LogService::requests('info', 'Expanded request search radius', [
                'request_id' => $request->id,
                'old_radius' => $this->currentRadius,
                'new_radius' => $nextRadius,
                'providers_notified' => $notifiedCount,
                'attempt' => $this->attempt
            ]);

            // Update request with current search radius
            $request->update([
                'current_search_radius' => $nextRadius,
                'expansion_attempts' => $this->attempt,
                'last_expansion_at' => now()
            ]);

            // Dispatch next expansion job if needed
            if ($nextRadius < 5) { // 5 is our max radius
                self::dispatch($request, $nextRadius, $this->attempt + 1)
                    ->delay(now()->addSeconds(10))
                    ->onQueue('request_expansion');
            }
        }
    }

    protected function getNextRadius($currentRadius)
    {
        $radiusSequence = [1, 3, 5];
        $currentIndex = array_search($currentRadius, $radiusSequence);

        return $radiusSequence[$currentIndex + 1] ?? null;
    }
}