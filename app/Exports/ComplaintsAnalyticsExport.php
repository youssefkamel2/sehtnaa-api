<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\Complaint;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ComplaintsAnalyticsExport implements FromCollection, WithHeadings, WithMapping
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
        return Complaint::with(['user', 'request'])
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Request ID',
            'User',
            'Subject',
            'Description',
            'Status',
            'Response',
            'Created At',
            'Resolved At',
            'Resolution Time (Hours)'
        ];
    }

    public function map($complaint): array
    {
        return [
            $complaint->id,
            $complaint->request_id,
            $complaint->user ? $complaint->user->full_name : 'N/A',
            $complaint->subject,
            $complaint->description,
            ucfirst(str_replace('_', ' ', $complaint->status)),
            $complaint->response ?? 'N/A',
            $complaint->created_at->toDateTimeString(),
            $complaint->resolved_at ? $complaint->resolved_at->toDateTimeString() : 'N/A',
            $complaint->resolved_at ? round($complaint->created_at->diffInHours($complaint->resolved_at), 2) : 'N/A'
        ];
    }
}