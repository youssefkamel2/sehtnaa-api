<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Carbon\Carbon;
use App\Models\Customer;

class CustomersAnalyticsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
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
        return Customer::with(['user', 'requests' => function($query) {
            $query->whereBetween('requests.created_at', [$this->startDate, $this->endDate]);
        }])
        ->whereBetween('customers.created_at', [$this->startDate, $this->endDate])
        ->withCount([
            'requests as total_requests',
            'requests as completed_requests' => function($query) {
                $query->where('status', 'completed');
            },
            'requests as pending_requests' => function($query) {
                $query->where('status', 'pending');
            },
            'requests as cancelled_requests' => function($query) {
                $query->where('status', 'cancelled');
            }
        ])
        ->withSum('requests', 'total_price')
        ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Customer Name',
            'Email',
            'Phone',
            'Gender',
            'Status',
            'Registration Date',
            'Total Requests',
            'Completed Requests',
            'Pending Requests',
            'Cancelled Requests',
            'Total Spending'
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->id,
            $customer->user->first_name . ' ' . $customer->user->last_name,
            $customer->user->email,
            $customer->user->phone,
            ucfirst($customer->user->gender),
            ucfirst($customer->user->status),
            $customer->created_at->format('Y-m-d H:i:s'),
            $customer->total_requests,
            $customer->completed_requests,
            $customer->pending_requests,
            $customer->cancelled_requests,
            number_format($customer->requests_sum_total_price, 2)
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text
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