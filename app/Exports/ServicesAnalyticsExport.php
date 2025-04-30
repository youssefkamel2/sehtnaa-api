<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\Service;
use App\Models\Request as ServiceRequest;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ServicesAnalyticsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
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
        return Service::with(['category', 'requests' => function ($query) {
            $query->whereBetween('requests.created_at', [$this->startDate, $this->endDate])
                ->withPivot('price');
        }])
            ->whereBetween('services.created_at', [$this->startDate, $this->endDate])
            ->withCount([
                'requests as total_requests',
                'requests as completed_requests' => function ($query) {
                    $query->where('requests.status', 'completed');
                },
                'requests as pending_requests' => function ($query) {
                    $query->where('requests.status', 'pending');
                },
                'requests as cancelled_requests' => function ($query) {
                    $query->where('requests.status', 'cancelled');
                }
            ])
            ->withSum(['requests' => function ($query) {
                $query->select(DB::raw('SUM(request_services.price)'));
            }], 'request_services.price')
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Service Name (EN)',
            'Service Name (AR)',
            'Category (EN)',
            'Category (AR)',
            'Provider Type',
            'Price',
            'Active Status',
            'Creation Date',
            'Total Requests',
            'Completed Requests',
            'Pending Requests',
            'Cancelled Requests',
            'Total Revenue',
            'Completion Rate'
        ];
    }

    public function map($service): array
    {
        // Handle multilingual names
        $serviceName = is_array($service->name) ? $service->name : (json_decode($service->name, true) ?? ['en' => $service->name, 'ar' => $service->name]);

        $categoryName = $service->category ?
            (is_array($service->category->name) ? $service->category->name : (json_decode($service->category->name, true) ?? ['en' => $service->category->name, 'ar' => $service->category->name])) :
            ['en' => 'Uncategorized', 'ar' => 'غير مصنف'];

        $completionRate = $service->total_requests > 0
            ? ($service->completed_requests / $service->total_requests) * 100
            : 0;

        return [
            $service->id,
            $serviceName['en'] ?? $service->name,
            $serviceName['ar'] ?? $service->name,
            $categoryName['en'] ?? 'Uncategorized',
            $categoryName['ar'] ?? 'غير مصنف',
            $service->provider_type,
            $service->price,
            $service->is_active ? 'Active' : 'Inactive',
            $service->created_at->format('Y-m-d H:i:s'),
            $service->total_requests,
            $service->completed_requests,
            $service->pending_requests,
            $service->cancelled_requests,
            number_format($service->requests_sum_request_services_price, 2),
            round($completionRate, 2) . '%'
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
