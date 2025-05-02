<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Models\Request as ServiceRequest;

class RequestsAnalyticsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
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
        return ServiceRequest::with(['customer.user', 'services.category', 'assignedProvider.user'])
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Customer Name',
            'Customer Phone',
            'Status',
            'Created At',
            'Completed At',
            'Services Count',
            'Services Names',
            'Categories',
            'Assigned Provider',
            'Total Price',
            'Completion Time (minutes)'
        ];
    }

    public function map($request): array
    {
        $completionTime = $request->completed_at && $request->started_at 
            ? $request->completed_at->diffInMinutes($request->started_at)
            : null;

        return [
            $request->id,
            $request->customer->user->name ?? 'N/A',
            $request->phone,
            ucfirst($request->status),
            $request->created_at->format('Y-m-d H:i:s'),
            $request->completed_at ? $request->completed_at->format('Y-m-d H:i:s') : 'N/A',
            $request->services->count(),
            $request->services->pluck('name')->implode(', '),
            $request->services->pluck('category.name')->unique()->implode(', '),
            $request->assignedProvider->user->name ?? 'N/A',
            $request->total_price,
            $completionTime
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