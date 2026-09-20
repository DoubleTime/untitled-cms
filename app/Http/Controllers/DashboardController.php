<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Support\DateBucket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index()
    {
        $startDate = Carbon::today()->subDays(6);

        $bucket = DateBucket::expression('created_at');

        $statsMap = EmailLog::query()
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw($bucket))
            ->orderBy(DB::raw($bucket))
            ->get([
                DB::raw("{$bucket} as day"),
                DB::raw('count(*) as sent'),
                // count() ignores NULLs, reproducing Mongo's $cond on $ne: null
                DB::raw('count(delivered_at) as delivered'),
            ])
            ->keyBy('day');

        $emailHealth = collect(range(6, 0))->map(function ($i) use ($statsMap) {
            $date = Carbon::today()->subDays($i);
            $dateStr = $date->format('Y-m-d');
            $dayData = $statsMap->get($dateStr);

            return [
                'name' => $date->format('D'),
                'sent' => (int) ($dayData['sent'] ?? 0),
                'delivered' => (int) ($dayData['delivered'] ?? 0),
            ];
        });

        return Inertia::render('Dashboard', [
            'emailHealth' => $emailHealth,
        ]);
    }
}
