<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class EmailLogController extends Controller
{
    /**
     * Display a listing of email logs.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', EmailLog::class);

        $query = EmailLog::query()->orderBy('created_at', 'desc');

        // Simple filtering
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('recipient', 'like', "%{$request->search}%")
                    ->orWhere('subject', 'like', "%{$request->search}%");
            });
        }

        $logs = $query->paginate(20)->withQueryString();

        $stats = Cache::remember('email_log_stats', 300, function () {
            // sum(case when ...) rather than Postgres FILTER, so the same SQL
            // runs on SQLite under test.
            $row = EmailLog::query()->get([
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status = 'delivered' then 1 else 0 end) as delivered"),
                DB::raw('sum(case when opened_at is not null then 1 else 0 end) as opened'),
                DB::raw("sum(case when status = 'bounced' then 1 else 0 end) as bounced"),
            ])->first();

            $total = (int) ($row->total ?? 0);
            $delivered = (int) ($row->delivered ?? 0);
            $opened = (int) ($row->opened ?? 0);
            $bounced = (int) ($row->bounced ?? 0);

            return [
                'total' => $total,
                'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
                'open_rate' => $total > 0 ? round(($opened / $total) * 100, 1) : 0,
                'bounce_rate' => $total > 0 ? round(($bounced / $total) * 100, 1) : 0,
            ];
        });

        return Inertia::render('EmailLogs/Index', [
            'logs' => $logs,
            'stats' => $stats,
            'filters' => $request->only(['status', 'search']),
        ]);
    }
}
