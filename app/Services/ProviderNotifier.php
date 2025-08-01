<?php

namespace App\Services;

use App\Models\Request as ServiceRequest;
use App\Models\Service;
use App\Models\Provider;
use App\Models\RequestProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\LogService;

class ProviderNotifier
{
    protected $firebaseService;
    protected $firestoreService;

    public function __construct(FirebaseService $firebaseService, FirestoreService $firestoreService)
    {
        $this->firebaseService = $firebaseService;
        $this->firestoreService = $firestoreService;
    }

    public function findAndNotifyProviders(ServiceRequest $serviceRequest, $radius)
    {
        $logData = $this->initializeLogData($serviceRequest, $radius);

        try {
            DB::beginTransaction();

            // Get the first service to determine provider type (since all services in request share same category)
            $primaryService = $serviceRequest->services->first();
            if (!$primaryService) {
                $this->logServiceNotFound($logData);
                return 0;
            }

            $providers = $this->getEligibleProviders($serviceRequest, $primaryService, $logData);
            $nearbyProviders = $this->findNearbyProviders($providers, $serviceRequest, $radius, $logData);

            if (count($nearbyProviders) === 0) {
                $this->logNoProvidersFound($logData);
                DB::commit();
                return 0;
            }

            $results = $this->processProviders($nearbyProviders, $serviceRequest, $radius, $logData);
            $this->logFinalResults($results, $logData);

            DB::commit();
            return $results['notified_count'];
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logCriticalError($e, $logData);
            return 0;
        }
    }

    protected function initializeLogData(ServiceRequest $request, $radius)
    {
        return [
            'request_id' => $request->id,
            'service_ids' => $request->services->pluck('id')->toArray(),
            'customer_id' => $request->customer_id,
            'search_radius' => $radius,
            'timestamp' => now()->toDateTimeString(),
            'execution_stage' => 'started',
            'total_providers_processed' => 0,
            'providers_within_radius' => 0,
            'providers_notified' => 0,
            'providers_skipped' => 0,
            'provider_details' => [],
            'errors' => []
        ];
    }

    protected function getEligibleProviders(ServiceRequest $request, $primaryService, &$logData)
    {
        $providers = Provider::with(['user'])
            ->where('is_available', true)
            ->where('provider_type', $primaryService->provider_type)
            ->whereNotIn('id', function ($query) use ($request) {
                $query->select('provider_id')
                    ->from('request_providers')
                    ->where('request_id', $request->id);
            })
            ->get();

        $logData['total_available_providers'] = $providers->count();
        $logData['service_names'] = $request->services->pluck('name')->toArray();
        $logData['required_provider_type'] = $primaryService->provider_type;
        $logData['execution_stage'] = 'providers_queried';

        return $providers;
    }

    protected function findNearbyProviders($providers, ServiceRequest $request, $radius, &$logData)
    {
        $nearbyProviders = [];

        foreach ($providers as $provider) {
            $providerLog = $this->createProviderLogEntry($provider);
            $logData['total_providers_processed']++;

            if (!$this->hasValidLocation($provider, $providerLog)) {
                $providerLog['skipped'] = true;
                $providerLog['skipped_reason'] = 'Invalid location';
                $logData['provider_details'][] = $providerLog;
                $logData['providers_skipped']++;
                continue;
            }

            try {
                $distance = $this->calculateDistance(
                    $request->latitude,
                    $request->longitude,
                    $provider->user->latitude,
                    $provider->user->longitude
                );

                $providerLog['distance'] = round($distance, 2);
                $providerLog['within_range'] = $distance <= $radius;

                if ($providerLog['within_range']) {
                    $logData['providers_within_radius']++;
                    $nearbyProviders[] = [
                        'provider' => $provider,
                        'distance' => $distance,
                        'log_entry' => $providerLog
                    ];
                }

                $logData['provider_details'][] = $providerLog;
            } catch (\Exception $e) {
                $providerLog['error'] = 'Distance calculation failed: ' . $e->getMessage();
                $providerLog['skipped'] = true;
                $providerLog['skipped_reason'] = 'Distance calculation error';
                $logData['errors'][] = $providerLog['error'];
                $logData['provider_details'][] = $providerLog;
                $logData['providers_skipped']++;
            }
        }

        $logData['execution_stage'] = 'distance_calculated';
        return $nearbyProviders;
    }

    protected function processProviders($nearbyProviders, ServiceRequest $request, $radius, &$logData)
    {
        $results = [
            'notified_count' => 0,
            'firestore_success' => 0,
            'firestore_failed' => 0,
            'notification_success' => 0,
            'notification_failed' => 0
        ];

        foreach ($nearbyProviders as $nearby) {
            $provider = $nearby['provider'];
            $distance = $nearby['distance'];
            $providerLog = $nearby['log_entry'];

            $providerLog['processing_started'] = true;
            $providerLog['database_recorded'] = false;
            $providerLog['notification_attempted'] = false;
            $providerLog['firestore_attempted'] = false;

            try {
                RequestProvider::create([
                    'request_id' => $request->id,
                    'provider_id' => $provider->id,
                    'status' => 'pending'
                ]);

                $providerLog['database_recorded'] = true;
                $results['notified_count']++;
                $logData['providers_notified']++;

                $providerLog['notification_attempted'] = true;
                $notificationResult = $this->sendProviderNotification($provider, $request, $distance, $radius, $providerLog);
                if ($notificationResult) {
                    $results['notification_success']++;
                    $providerLog['notification_sent'] = true;
                } else {
                    $results['notification_failed']++;
                    $providerLog['notification_sent'] = false;
                }

                $providerLog['firestore_attempted'] = true;
                $firestoreResult = $this->updateFirestore($provider, $request, $distance, $radius, $providerLog);
                if ($firestoreResult) {
                    $results['firestore_success']++;
                    $providerLog['firestore_updated'] = true;
                } else {
                    $results['firestore_failed']++;
                    $providerLog['firestore_updated'] = false;
                }
            } catch (\Exception $e) {
                $providerLog['error'] = 'Processing failed: ' . $e->getMessage();
                $logData['errors'][] = $providerLog['error'];

                if ($providerLog['notification_attempted'] && !$providerLog['notification_sent']) {
                    $results['notification_failed']++;
                }
                if ($providerLog['firestore_attempted'] && !$providerLog['firestore_updated']) {
                    $results['firestore_failed']++;
                }
            }

            foreach ($logData['provider_details'] as &$existingLog) {
                if ($existingLog['provider_id'] == $providerLog['provider_id']) {
                    $existingLog = array_merge($existingLog, $providerLog);
                    break;
                }
            }
        }

        $logData['execution_stage'] = 'providers_processed';
        return $results;
    }

    protected function createProviderLogEntry($provider)
    {
        return [
            'provider_id' => $provider->id,
            'provider_name' => $provider->user->first_name ?? 'Provider #' . $provider->id,
            'has_location' => false,
            'distance' => null,
            'within_range' => false,
            'notification_sent' => false,
            'notification_error' => null,
            'firestore_updated' => false,
            'firestore_error' => null,
            'processing_time' => microtime(true),
            'skipped' => false,
            'skipped_reason' => null
        ];
    }

    protected function hasValidLocation($provider, &$providerLog)
    {
        if (!$provider->user->latitude || !$provider->user->longitude) {
            $providerLog['location_status'] = 'Missing coordinates';
            return false;
        }

        $providerLog['has_location'] = true;
        $providerLog['location_status'] = 'Coordinates available';
        return true;
    }

    protected function sendProviderNotification($provider, $request, $distance, $radius, &$providerLog)
    {
        if (empty($provider->user->fcm_token)) {
            $providerLog['notification_error'] = 'No FCM token available';
            return false;
        }

        try {
            $serviceNames = $request->services->pluck('name')->implode(', ');

            $response = $this->firebaseService->sendToDevice(
                $provider->user->fcm_token,
                'New Service Request',
                'You have a new service request nearby for: ' . $serviceNames,
                [
                    'type' => 'new_request',
                    'request_id' => $request->id,
                    'distance' => $distance,
                    'search_radius' => $radius,
                    'total_price' => $request->total_price,
                    'service_count' => $request->services->count()
                ]
            );

            if (isset($response['failure']) && $response['failure'] > 0) {
                $error = $response['results'][0]['error'] ?? 'Unknown FCM error';
                $providerLog['notification_error'] = 'FCM Delivery Error: ' . $error;
                return false;
            }

            $providerLog['notification_sent'] = true;
            $providerLog['notification_response'] = $response;
            return true;
        } catch (\Exception $e) {
            $providerLog['notification_error'] = 'FCM Error: ' . $e->getMessage();
            return false;
        }
    }

    protected function updateFirestore($provider, $request, $distance, $radius, &$providerLog)
    {
        try {

            // Get customer name (assuming Customer is a User model)
            $customerName = 'Unknown Customer';
            if ($request->customer) {
                $customerName = $request->customer->user->first_name . ' ' . $request->customer->user->last_name;
            }


            $data = [
                'request_id' => (string) $request->id,
                'customer_name' => $customerName,
                'customer_image' => $request->customer->user->profile_image ?? null,
                'services_count' => $request->services->count(),
                'total_price' => $request->total_price,
                'gender' => $request->gender ?? 'unknown',
                'distance' => round($distance, 2) . ' km',
                'search_radius' => $radius . ' km',
                'timestamp' => now()->toDateTimeString(),
                'status' => 'pending'
            ];

            $result = $this->firestoreService->createDocument(
                'provider_requests/' . $provider->user->id . '/notifications',
                (string) $request->id,
                $data
            );

            if ($result === false) {
                $providerLog['firestore_error'] = 'Firestore operation failed without exception';
                return false;
            }

            $providerLog['firestore_updated'] = true;
            return true;
        } catch (\Exception $e) {
            $providerLog['firestore_error'] = 'Firestore Error: ' . $e->getMessage();
            return false;
        }
    }

    protected function logServiceNotFound(&$logData)
    {
        $logData['error'] = 'Service not found';
        $logData['execution_stage'] = 'failed';
        LogService::providers('error', 'Service not found', $logData);
    }

    protected function logNoProvidersFound(&$logData)
    {
        $logData['outcome'] = 'No providers found in radius';
        $logData['execution_stage'] = 'completed';
        LogService::providers('info', 'No providers found', $logData);
    }

    protected function logFinalResults($results, &$logData)
    {
        $logData['notified_count'] = $results['notified_count'];
        $logData['firestore_success'] = $results['firestore_success'];
        $logData['firestore_failed'] = $results['firestore_failed'];
        $logData['notification_success'] = $results['notification_success'];
        $logData['notification_failed'] = $results['notification_failed'];
        $logData['execution_stage'] = 'completed';

        LogService::providers('info', 'Provider notification results', $logData);
    }

    protected function logCriticalError($e, &$logData)
    {
        $logData['error'] = $e->getMessage();
        $logData['trace'] = $e->getTraceAsString();
        $logData['execution_stage'] = 'failed';
        LogService::providers('error', 'Critical error in provider notification', $logData);
    }

    protected function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
