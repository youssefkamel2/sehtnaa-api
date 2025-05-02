<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProvidersAnalyticsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $startDate;
    protected $endDate;

    public function __construct($startDate, $endDate)
    {
        $this->startDate = Carbon::parse($startDate)->startOfDay();
        $this->endDate = Carbon::parse($endDate)->endOfDay();
    }

    public function collection()
    {
        return Provider::with(['user'])
            ->withCount([
                // Count requests where provider is assigned
                'assignedRequests as total_requests' => function($query) {
                    $query->whereBetween('created_at', [$this->startDate, $this->endDate]);
                },
                'assignedRequests as completed_requests' => function($query) {
                    $query->where('status', 'completed')
                          ->whereBetween('created_at', [$this->startDate, $this->endDate]);
                },
                'assignedRequests as pending_requests' => function($query) {
                    $query->where('status', 'pending')
                          ->whereBetween('created_at', [$this->startDate, $this->endDate]);
                },
                'assignedRequests as cancelled_requests' => function($query) {
                    $query->where('status', 'cancelled')
                          ->whereBetween('created_at', [$this->startDate, $this->endDate]);
                }
            ])
            ->withCount([
                // Count offers through request_providers
                'requestProviders as total_offers' => function($query) {
                    $query->whereBetween('request_providers.created_at', [$this->startDate, $this->endDate]);
                },
                'requestProviders as accepted_offers' => function($query) {
                    $query->where('status', 'accepted')
                          ->whereBetween('request_providers.created_at', [$this->startDate, $this->endDate]);
                },
                'requestProviders as rejected_offers' => function($query) {
                    $query->where('status', 'rejected')
                          ->whereBetween('request_providers.created_at', [$this->startDate, $this->endDate]);
                },
                'requestProviders as pending_offers' => function($query) {
                    $query->where('status', 'pending')
                          ->whereBetween('request_providers.created_at', [$this->startDate, $this->endDate]);
                }
            ])
            ->withSum([
                'assignedRequests as requests_sum_total_price' => function($query) {
                    $query->whereBetween('created_at', [$this->startDate, $this->endDate]);
                }
            ], 'total_price')
            ->whereBetween('providers.created_at', [$this->startDate, $this->endDate])
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Provider Name',
            'Email',
            'Phone',
            'Provider Type',
            'Status',
            'Registration Date',
            'Total Assigned Requests',
            'Completed Requests',
            'Pending Requests',
            'Cancelled Requests',
            'Total Offers Received',
            'Accepted Offers',
            'Rejected Offers',
            'Pending Offers',
            'Acceptance Rate',
            'Total Revenue'
        ];
    }

    public function map($provider): array
    {
        $acceptanceRate = $provider->total_offers > 0 
            ? ($provider->accepted_offers / $provider->total_offers) * 100 
            : 0;

        return [
            $provider->id,
            $provider->user->first_name . ' ' . $provider->user->last_name,
            $provider->user->email,
            $provider->user->phone,
            ucfirst($provider->provider_type),
            ucfirst($provider->user->status),
            $provider->created_at->format('Y-m-d H:i:s'),
            $provider->total_requests,
            $provider->completed_requests,
            $provider->pending_requests,
            $provider->cancelled_requests,
            $provider->total_offers,
            $provider->accepted_offers,
            $provider->rejected_offers,
            $provider->pending_offers,
            round($acceptanceRate, 2) . '%',
            number_format($provider->requests_sum_total_price ?? 0, 2)
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'color' => ['argb' => 'FFD9D9D9']
                ]
            ],
        ];
    }
}