<?php

namespace App\Filament\Widgets;

use App\Models\EmailSentLog;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class EmailSendingChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Email Sending Activity (Last 7 Days)';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $data = EmailSentLog::where('sent_at', '>=', now()->subDays(7))
            ->select(
                DB::raw('DATE(sent_at) as date'),
                DB::raw('COUNT(*) as count'),
                'status'
            )
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get();

        $dates = [];
        $sentCounts = [];
        $failedCounts = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dates[] = now()->subDays($i)->format('M d');

            $sent = $data->where('date', $date)
                ->where('status', EmailSentLog::STATUS_SENT)
                ->first();
            $failed = $data->where('date', $date)
                ->where('status', EmailSentLog::STATUS_FAILED)
                ->first();

            $sentCounts[] = $sent ? $sent->count : 0;
            $failedCounts[] = $failed ? $failed->count : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Sent',
                    'data' => $sentCounts,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.2)',
                    'borderColor' => 'rgb(34, 197, 94)',
                ],
                [
                    'label' => 'Failed',
                    'data' => $failedCounts,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                    'borderColor' => 'rgb(239, 68, 68)',
                ],
            ],
            'labels' => $dates,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
