<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use App\Models\Service;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Complaint;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use App\Exports\RequestsAnalyticsExport;
use App\Exports\ServicesAnalyticsExport;
use App\Exports\CustomersAnalyticsExport;
use App\Exports\ProvidersAnalyticsExport;
use App\Models\Request as ServiceRequest;
use Illuminate\Support\Facades\Validator;

class AnalyticsController extends Controller
{
    use ResponseTrait;

    public const EXPORT_FILE_LIFETIME = 1;

    // Customer Analytics
    public function customerAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();

        // Basic metrics
        $totalCustomers = Customer::whereBetween('created_at', [$startDate, $endDate])->count();
        $activeCustomers = Customer::whereHas('user', fn($q) => $q->where('status', 'active'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Growth metrics
        $previousPeriodCustomers = Customer::whereBetween('created_at', [
            $startDate->copy()->subDays($startDate->diffInDays($endDate)),
            $startDate
        ])->count();

        $growthRate = $previousPeriodCustomers > 0
            ? (($totalCustomers - $previousPeriodCustomers) / $previousPeriodCustomers) * 100
            : 100;

        // Customer activity
        $activeRequesters = ServiceRequest::whereBetween('created_at', [$startDate, $endDate])
            ->distinct('customer_id')
            ->count('customer_id');

        // Gender distribution - optimized for pie/donut charts
        $genderDistribution = Customer::join('users', 'customers.user_id', '=', 'users.id')
            ->whereBetween('customers.created_at', [$startDate, $endDate])
            ->select('users.gender', DB::raw('count(*) as count'))
            ->groupBy('users.gender')
            ->get();

        $genderChartData = [
            'labels' => $genderDistribution->pluck('gender')->map(fn($g) => ucfirst($g))->toArray(),
            'values' => $genderDistribution->pluck('count')->toArray()
        ];

        // Registration trends - optimized for line/bar charts
        $registrationTrends = Customer::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $registrationTrendsChartData = [
            'labels' => $registrationTrends->pluck('date')->toArray(),
            'values' => $registrationTrends->pluck('count')->toArray()
        ];

        // Top customers by requests - optimized for bar charts
        $topCustomers = DB::table('requests')
            ->select(
                'requests.customer_id',
                DB::raw('count(*) as request_count'),
                'users.first_name',
                'users.last_name'
            )
            ->join('customers', 'requests.customer_id', '=', 'customers.id')
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->whereBetween('requests.created_at', [$startDate, $endDate])
            ->groupBy('requests.customer_id', 'users.first_name', 'users.last_name')
            ->orderByDesc('request_count')
            ->limit(10)
            ->get();

        $topCustomersChartData = [
            'labels' => $topCustomers->map(fn($c) => $c->first_name . ' ' . $c->last_name)->toArray(),
            'values' => $topCustomers->pluck('request_count')->toArray()
        ];

        return $this->success([
            'summary' => [
                'total_customers' => $totalCustomers,
                'active_customers' => $activeCustomers,
                'growth_rate' => round($growthRate, 2),
                'active_requesters' => $activeRequesters,
                'percentage_active' => $totalCustomers > 0 ? round(($activeRequesters / $totalCustomers) * 100, 2) : 0,
            ],
            'charts' => [
                'gender_distribution' => $genderChartData,
                'registration_trends' => $registrationTrendsChartData,
                'top_customers' => $topCustomersChartData
            ],
            'detailed_data' => [
                'gender_distribution' => $genderDistribution,
                'registration_trends' => $registrationTrends,
                'top_customers' => $topCustomers
            ],
            'export_url' => route('admin.analytics.customers.export', [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date
            ])
        ], 'Customer analytics retrieved successfully');
    }

    public function exportCustomerAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        // Generate a unique file name
        $fileName = 'customers-export-' . time() . '.xlsx';
        $path = 'exports/' . $fileName;

        // Store the file
        Excel::store(
            new CustomersAnalyticsExport($request->start_date, $request->end_date),
            $path,
            'public'
        );

        // Return the download URL
        return $this->success([
            'download_url' => Storage::url($path),
            'expires_at' => now()->addHours(self::EXPORT_FILE_LIFETIME)->toDateTimeString()
        ], 'Export ready for download');
    }

    public function serviceAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();

        // Basic metrics
        $totalServices = Service::whereBetween('created_at', [$startDate, $endDate])->count();
        $activeServices = Service::where('is_active', true)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Growth metrics
        $previousPeriodServices = Service::whereBetween('created_at', [
            $startDate->copy()->subDays($startDate->diffInDays($endDate)),
            $startDate
        ])->count();

        $growthRate = $previousPeriodServices > 0
            ? (($totalServices - $previousPeriodServices) / $previousPeriodServices) * 100
            : 100;

        // Service distribution by category - optimized for pie/donut charts
        $categoryDistribution = Service::with(['category' => function ($query) {
            $query->select('id', 'name');
        }])
            ->select('category_id', DB::raw('count(*) as count'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('category_id')
            ->get()
            ->map(function ($item) {
                $categoryName = $item->category ? $item->category->name : ['en' => 'Uncategorized', 'ar' => 'غير مصنف'];
                return [
                    'category' => $categoryName,
                    'count' => $item->count
                ];
            });

        // Format category distribution for charts
        $categoryChartData = [
            'labels' => $categoryDistribution->pluck('category.en')->toArray(),
            'values' => $categoryDistribution->pluck('count')->toArray(),
            'labels_ar' => $categoryDistribution->pluck('category.ar')->toArray()
        ];

        // Service distribution by provider type - optimized for pie/donut charts
        $providerTypeDistribution = Service::select(
            'provider_type',
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('provider_type')
            ->get();

        $providerTypeChartData = [
            'labels' => $providerTypeDistribution->pluck('provider_type')->toArray(),
            'values' => $providerTypeDistribution->pluck('count')->toArray()
        ];

        // Time-based service creation data - optimized for line/bar charts
        $creationTrends = Service::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $creationTrendsChartData = [
            'labels' => $creationTrends->pluck('date')->toArray(),
            'values' => $creationTrends->pluck('count')->toArray()
        ];

        // Helper function to handle multilingual names
        $getName = function ($name) {
            if (is_array($name)) {
                return $name;
            }
            if (is_string($name) && json_decode($name)) {
                return json_decode($name, true);
            }
            return [
                'en' => $name,
                'ar' => $name
            ];
        };

        // Top services by requests - optimized for bar charts
        $topServicesByRequests = DB::table('request_services')
            ->select(
                'services.id',
                'services.name',
                DB::raw('count(request_services.id) as request_count'),
                DB::raw('sum(request_services.price) as total_revenue')
            )
            ->join('services', 'request_services.service_id', '=', 'services.id')
            ->join('requests', 'request_services.request_id', '=', 'requests.id')
            ->whereBetween('requests.created_at', [$startDate, $endDate])
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('request_count')
            ->limit(10)
            ->get()
            ->map(function ($service) use ($getName) {
                $name = $getName($service->name);
                return [
                    'id' => $service->id,
                    'name' => $name,
                    'request_count' => $service->request_count,
                    'total_revenue' => $service->total_revenue
                ];
            });

        $topServicesRequestsChartData = [
            'labels' => $topServicesByRequests->pluck('name.en')->toArray(),
            'values' => $topServicesByRequests->pluck('request_count')->toArray(),
            'labels_ar' => $topServicesByRequests->pluck('name.ar')->toArray()
        ];

        // Top services by revenue - optimized for bar charts
        $topServicesByRevenue = DB::table('request_services')
            ->select(
                'services.id',
                'services.name',
                DB::raw('count(request_services.id) as request_count'),
                DB::raw('sum(request_services.price) as total_revenue')
            )
            ->join('services', 'request_services.service_id', '=', 'services.id')
            ->join('requests', 'request_services.request_id', '=', 'requests.id')
            ->whereBetween('requests.created_at', [$startDate, $endDate])
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get()
            ->map(function ($service) use ($getName) {
                $name = $getName($service->name);
                return [
                    'id' => $service->id,
                    'name' => $name,
                    'request_count' => $service->request_count,
                    'total_revenue' => $service->total_revenue
                ];
            });

        $topServicesRevenueChartData = [
            'labels' => $topServicesByRevenue->pluck('name.en')->toArray(),
            'values' => $topServicesByRevenue->pluck('total_revenue')->toArray(),
            'labels_ar' => $topServicesByRevenue->pluck('name.ar')->toArray()
        ];

        return $this->success([
            'summary' => [
                'total_services' => $totalServices,
                'active_services' => $activeServices,
                'inactive_services' => $totalServices - $activeServices,
                'growth_rate' => round($growthRate, 2),
            ],
            'charts' => [
                'category_distribution' => $categoryChartData,
                'provider_type_distribution' => $providerTypeChartData,
                'creation_trends' => $creationTrendsChartData,
                'top_services_by_requests' => $topServicesRequestsChartData,
                'top_services_by_revenue' => $topServicesRevenueChartData
            ],
            'detailed_data' => [
                'category_distribution' => $categoryDistribution,
                'provider_type_distribution' => $providerTypeDistribution,
                'creation_trends' => $creationTrends,
                'top_services_by_requests' => $topServicesByRequests,
                'top_services_by_revenue' => $topServicesByRevenue
            ],
            'export_url' => route('admin.analytics.services.export', [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date
            ])
        ], 'Service analytics retrieved successfully');
    }

    public function exportServiceAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        // Generate a unique file name
        $fileName = 'services-export-' . time() . '.xlsx';
        $path = 'exports/' . $fileName;

        // Store the file
        Excel::store(
            new ServicesAnalyticsExport($request->start_date, $request->end_date),
            $path,
            'public'
        );

        // Return the download URL
        return $this->success([
            'download_url' => Storage::url($path),
            'expires_at' => now()->addHours(self::EXPORT_FILE_LIFETIME)->toDateTimeString()
        ], 'Export ready for download');
    }

    // Provider Analytics
    public function providerAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();

        // Basic metrics
        $totalProviders = Provider::whereBetween('created_at', [$startDate, $endDate])->count();
        $activeProviders = Provider::whereHas('user', fn($q) => $q->where('status', 'active'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Growth metrics
        $previousPeriodProviders = Provider::whereBetween('created_at', [
            $startDate->copy()->subDays($startDate->diffInDays($endDate)),
            $startDate
        ])->count();

        $growthRate = $previousPeriodProviders > 0
            ? (($totalProviders - $previousPeriodProviders) / $previousPeriodProviders) * 100
            : 100;

        // Provider type distribution
        $providerTypeDistribution = Provider::select(
            'provider_type',
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('provider_type')
            ->get();

        $providerTypeChartData = [
            'labels' => $providerTypeDistribution->pluck('provider_type')->toArray(),
            'values' => $providerTypeDistribution->pluck('count')->toArray()
        ];

        // Time-based provider creation data
        $creationTrends = Provider::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $creationTrendsChartData = [
            'labels' => $creationTrends->pluck('date')->toArray(),
            'values' => $creationTrends->pluck('count')->toArray()
        ];

        // Request response analysis
        $requestResponseStats = DB::table('request_providers')
            ->select(
                'providers.id',
                'users.first_name',
                'users.last_name',
                DB::raw('count(case when request_providers.status = "accepted" then 1 end) as accepted_count'),
                DB::raw('count(case when request_providers.status = "rejected" then 1 end) as rejected_count'),
                DB::raw('count(case when request_providers.status = "pending" then 1 end) as pending_count'),
                DB::raw('count(*) as total_offers')
            )
            ->join('providers', 'request_providers.provider_id', '=', 'providers.id')
            ->join('users', 'providers.user_id', '=', 'users.id')
            ->whereBetween('request_providers.created_at', [$startDate, $endDate])
            ->groupBy('providers.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('total_offers')
            ->limit(10)
            ->get();

        $requestResponseChartData = [
            'labels' => $requestResponseStats->map(fn($p) => $p->first_name . ' ' . $p->last_name)->toArray(),
            'accepted' => $requestResponseStats->pluck('accepted_count')->toArray(),
            'rejected' => $requestResponseStats->pluck('rejected_count')->toArray(),
            'pending' => $requestResponseStats->pluck('pending_count')->toArray()
        ];

        // Top performing providers (by completed requests)
        $topProviders = DB::table('requests')
            ->select(
                'providers.id',
                'users.first_name',
                'users.last_name',
                DB::raw('count(*) as completed_requests'),
                DB::raw('sum(requests.total_price) as total_revenue')
            )
            ->join('providers', 'requests.assigned_provider_id', '=', 'providers.id')
            ->join('users', 'providers.user_id', '=', 'users.id')
            ->where('requests.status', 'completed')
            ->whereBetween('requests.completed_at', [$startDate, $endDate])
            ->groupBy('providers.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('completed_requests')
            ->limit(10)
            ->get();

        $topProvidersChartData = [
            'labels' => $topProviders->map(fn($p) => $p->first_name . ' ' . $p->last_name)->toArray(),
            'completed_requests' => $topProviders->pluck('completed_requests')->toArray(),
            'revenue' => $topProviders->pluck('total_revenue')->toArray()
        ];

        return $this->success([
            'summary' => [
                'total_providers' => $totalProviders,
                'active_providers' => $activeProviders,
                'inactive_providers' => $totalProviders - $activeProviders,
                'growth_rate' => round($growthRate, 2),
            ],
            'charts' => [
                'provider_type_distribution' => $providerTypeChartData,
                'creation_trends' => $creationTrendsChartData,
                'request_response_analysis' => $requestResponseChartData,
                'top_performing_providers' => $topProvidersChartData
            ],
            'detailed_data' => [
                'provider_type_distribution' => $providerTypeDistribution,
                'creation_trends' => $creationTrends,
                'request_response_stats' => $requestResponseStats,
                'top_performing_providers' => $topProviders
            ],
            'export_url' => route('admin.analytics.providers.export', [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date
            ])
        ], 'Provider analytics retrieved successfully');
    }

    public function exportProviderAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        // Generate a unique file name
        $fileName = 'providers-export-' . time() . '.xlsx';
        $path = 'exports/' . $fileName;

        // Store the file
        Excel::store(
            new ProvidersAnalyticsExport($request->start_date, $request->end_date),
            $path,
            'public'
        );

        // Return the download URL
        return $this->success([
            'download_url' => Storage::url($path),
            'expires_at' => now()->addHours(self::EXPORT_FILE_LIFETIME)->toDateTimeString()
        ], 'Export ready for download');
    }


    public function requestAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();

        // Basic metrics
        $totalRequests = ServiceRequest::whereBetween('created_at', [$startDate, $endDate])->count();
        $completedRequests = ServiceRequest::where('status', 'completed')
            ->whereBetween('completed_at', [$startDate, $endDate])
            ->count();
        $cancelledRequests = ServiceRequest::where('status', 'cancelled')
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->count();
        $pendingRequests = ServiceRequest::where('status', 'pending')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();
        $acceptedRequests = ServiceRequest::where('status', 'accepted')
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->count();

        // Growth metrics
        $previousPeriodRequests = ServiceRequest::whereBetween('created_at', [
            $startDate->copy()->subDays($startDate->diffInDays($endDate)),
            $startDate
        ])->count();

        $growthRate = $previousPeriodRequests > 0
            ? (($totalRequests - $previousPeriodRequests) / $previousPeriodRequests) * 100
            : 100;

        // Status distribution
        $statusDistribution = ServiceRequest::select(
            'status',
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('status')
            ->get();

        $statusChartData = [
            'labels' => $statusDistribution->pluck('status')->map(fn($s) => ucfirst($s))->toArray(),
            'values' => $statusDistribution->pluck('count')->toArray()
        ];

        // Request trends over time
        $requestTrends = ServiceRequest::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $requestTrendsChartData = [
            'labels' => $requestTrends->pluck('date')->toArray(),
            'values' => $requestTrends->pluck('count')->toArray()
        ];

        // Completion time analysis
        $completionTimes = ServiceRequest::select(
            DB::raw('AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as avg_completion_time'),
            DB::raw('MAX(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as max_completion_time'),
            DB::raw('MIN(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as min_completion_time')
        )
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$startDate, $endDate])
            ->first();

        // Revenue analysis
        $revenueStats = ServiceRequest::select(
            DB::raw('SUM(total_price) as total_revenue'),
            DB::raw('AVG(total_price) as avg_revenue_per_request')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->first();

        // Helper function to handle multilingual names
        $getName = function ($name) {
            if (is_array($name)) {
                return $name;
            }
            if (is_string($name) && json_decode($name)) {
                return json_decode($name, true);
            }
            return [
                'en' => $name,
                'ar' => $name
            ];
        };

        // Top categories by request count
        $topCategories = DB::table('request_services')
            ->select(
                'categories.id',
                'categories.name',
                DB::raw('count(*) as request_count')
            )
            ->join('services', 'request_services.service_id', '=', 'services.id')
            ->join('categories', 'services.category_id', '=', 'categories.id')
            ->join('requests', 'request_services.request_id', '=', 'requests.id')
            ->whereBetween('requests.created_at', [$startDate, $endDate])
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('request_count')
            ->limit(5)
            ->get()
            ->map(function ($category) use ($getName) {
                $name = $getName($category->name);
                return [
                    'id' => $category->id,
                    'name' => $name,
                    'request_count' => $category->request_count
                ];
            });

        $topCategoriesChartData = [
            'labels' => $topCategories->pluck('name.en')->toArray(),
            'values' => $topCategories->pluck('request_count')->toArray(),
            'labels_ar' => $topCategories->pluck('name.ar')->toArray()
        ];

        // Cancellation reasons (if you have this data)
        $cancellationReasons = DB::table('request_cancellation_logs')
            ->select(
                'reason',
                DB::raw('count(*) as count')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('reason')
            ->orderByDesc('count')
            ->get()
            ->map(function ($reason) use ($getName) {
                $reasonText = $getName($reason->reason);
                return [
                    'reason' => $reasonText,
                    'count' => $reason->count
                ];
            });

        $cancellationChartData = [
            'labels' => $cancellationReasons->pluck('reason.en')->toArray(),
            'values' => $cancellationReasons->pluck('count')->toArray(),
            'labels_ar' => $cancellationReasons->pluck('reason.ar')->toArray()
        ];

        return $this->success([
            'summary' => [
                'total_requests' => $totalRequests,
                'completed_requests' => $completedRequests,
                'cancelled_requests' => $cancelledRequests,
                'pending_requests' => $pendingRequests,
                'accepted_requests' => $acceptedRequests,
                'completion_rate' => $totalRequests > 0 ? round(($completedRequests / $totalRequests) * 100, 2) : 0,
                'cancellation_rate' => $totalRequests > 0 ? round(($cancelledRequests / $totalRequests) * 100, 2) : 0,
                'growth_rate' => round($growthRate, 2),
                'avg_completion_time_minutes' => round($completionTimes->avg_completion_time ?? 0, 2),
                'total_revenue' => number_format($revenueStats->total_revenue ?? 0, 2, '.', ''),
                'avg_revenue_per_request' => number_format($revenueStats->avg_revenue_per_request ?? 0, 6, '.', '')
            ],
            'charts' => [
                'status_distribution' => $statusChartData,
                'request_trends' => $requestTrendsChartData,
                'top_categories' => $topCategoriesChartData,
                'cancellation_reasons' => $cancellationChartData
            ],
            'detailed_data' => [
                'status_distribution' => $statusDistribution,
                'request_trends' => $requestTrends,
                'top_categories' => $topCategories,
                'cancellation_reasons' => $cancellationReasons,
                'completion_times' => [
                    'avg_completion_time' => $completionTimes->avg_completion_time ?? null,
                    'max_completion_time' => $completionTimes->max_completion_time ?? null,
                    'min_completion_time' => $completionTimes->min_completion_time ?? null
                ],
                'revenue_stats' => [
                    'total_revenue' => number_format($revenueStats->total_revenue ?? 0, 2, '.', ''),
                    'avg_revenue_per_request' => number_format($revenueStats->avg_revenue_per_request ?? 0, 6, '.', '')
                ]
            ],
            'export_url' => route('admin.analytics.requests.export', [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date
            ])
        ], 'Request analytics retrieved successfully');
    }

    public function exportRequestAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        // Generate a unique file name
        $fileName = 'requests-export-' . time() . '.xlsx';
        $path = 'exports/' . $fileName;

        // Store the file
        Excel::store(
            new RequestsAnalyticsExport($request->start_date, $request->end_date),
            $path,
            'public'
        );

        // Return the download URL
        return $this->success([
            'download_url' => Storage::url($path),
            'expires_at' => now()->addHours(self::EXPORT_FILE_LIFETIME)->toDateTimeString()
        ], 'Export ready for download');
    }


    public static function cleanupExpiredExports()
    {
        $directory = 'exports/';
        $files = Storage::disk('public')->files($directory);
        $now = now()->timestamp;
        $deletedCount = 0;
        $cutoffTime = $now - (self::EXPORT_FILE_LIFETIME * 3600); // 1 hour in seconds

        foreach ($files as $file) {
            try {
                // Get file creation time from filename
                $fileName = pathinfo($file, PATHINFO_FILENAME);
                preg_match('/-(\d+)\./', $file, $matches);

                if (isset($matches[1])) {
                    $fileTime = (int)$matches[1];

                    if ($fileTime < $cutoffTime) {
                        Storage::disk('public')->delete($file);
                        $deletedCount++;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to delete export file: {$file}", ['error' => $e->getMessage()]);
            }
        }

        return $deletedCount;
    }
}
