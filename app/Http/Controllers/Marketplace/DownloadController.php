<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Download;
use App\Models\UnysisBox;
use App\Models\User;
use App\Services\Marketplace\DownloadPresenter;
use App\Services\Marketplace\DownloadQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Download log — every recorded fetch of a Revision file, from RPA-TOOL
 * (`api`) and from Team Members on the admin pages (`web`).
 *
 * Download rows are append-only and are never deleted, not even when the entry
 * or the Revision they point at is hard deleted, so this is the durable record
 * of what left the Marketplace.
 *
 * The filter builder lives in App\Services\Marketplace\DownloadQuery so the index, the CSV
 * export and the Usage report all narrow the table identically.
 *
 * Both routes sit inside the `can:downloads.view` group in routes/web.php; that
 * middleware is the permission check, and the controller does not repeat it.
 */
class DownloadController extends Controller
{
    private const PER_PAGE = 50;

    /** Rows pulled per chunk while streaming the export. */
    private const EXPORT_CHUNK = 500;

    public function index(Request $request)
    {
        $filters = DownloadQuery::filters($request);

        $downloads = DownloadQuery::build($filters)
            ->with(['user:id,name', 'revision:id,number', 'unysisBox:id,name,motherboard_uuid'])
            ->orderByDesc('downloads.created_at')
            ->orderByDesc('downloads.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $names = DownloadPresenter::entryNames(collect($downloads->items()));

        return Inertia::render('Marketplace/Downloads/Index', [
            'downloads' => $downloads->through(
                fn (Download $download) => DownloadPresenter::present($download, $names)
            ),
            'filters' => $filters,
            'summary' => $this->summary($filters),
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
            'unysisBoxes' => UnysisBox::query()
                ->with('customer:id,company')
                ->orderBy('motherboard_uuid')
                ->get(['id', 'name', 'motherboard_uuid', 'customer_id']),
            'users' => User::query()
                ->whereIn('id', Download::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'customer_id']),
        ]);
    }

    /**
     * The currently filtered log as CSV.
     *
     * Streamed and chunked: the row set is unbounded, so nothing larger than one
     * chunk is ever held in memory and the response starts before the query ends.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = DownloadQuery::filters($request);
        $filename = 'downloads-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'downloaded_at', 'source', 'entry_type', 'entry_name',
                'machine_model', 'machine_brand', 'revision_number', 'revision_status',
                'customer_code', 'customer_company', 'unysis_box_uuid', 'unysis_box_name',
                'user_name', 'user_email', 'ip',
            ]);

            DownloadQuery::build($filters)
                ->with([
                    'user:id,name,email,customer_id',
                    'user.customer:id,code,company',
                    'revision:id,number,status',
                    'unysisBox:id,name,motherboard_uuid,customer_id',
                    'unysisBox.customer:id,code,company',
                ])
                ->orderBy('downloads.id')
                ->chunk(self::EXPORT_CHUNK, function (Collection $rows) use ($handle) {
                    // One extra pair of queries per chunk resolves the entry names,
                    // including entries that have since been soft or hard deleted.
                    $names = DownloadPresenter::entryNames($rows);

                    foreach ($rows as $download) {
                        fputcsv($handle, $this->exportRow($download, $names));
                    }

                    flush();
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $names
     * @return array<int, string|int|null>
     */
    private function exportRow(Download $download, array $names): array
    {
        $entry = $names[$download->revisable_type.':'.$download->revisable_id] ?? [];

        // A Download belongs to a Customer through its UNYSIS Box, or — for a web
        // fetch with no box — through the Customer User who made it.
        $customer = $download->unysisBox?->customer ?? $download->user?->customer;

        return [
            $download->created_at?->toIso8601String(),
            $download->source,
            $download->revisable_type,
            $entry['name'] ?? null,
            $entry['machine_model'] ?? null,
            $entry['machine_brand'] ?? null,
            $download->revision?->number,
            $download->revision?->status,
            $customer?->code,
            $customer?->company,
            $download->unysisBox?->motherboard_uuid,
            $download->unysisBox?->name,
            $download->user?->name,
            $download->user?->email,
            $download->ip,
        ];
    }

    /**
     * The strip above the table, computed over the *filtered* set with grouped
     * queries — four in total, whatever the row count.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    private function summary(array $filters): array
    {
        $total = DownloadQuery::build($filters)->count();

        $lastSevenDays = DownloadQuery::build($filters)
            ->where('downloads.created_at', '>=', now()->subDays(7))
            ->count();

        $uniqueBoxes = DownloadQuery::build($filters)
            ->whereNotNull('downloads.unysis_box_id')
            ->distinct()
            ->count('unysis_box_id');

        $top = DownloadQuery::build($filters)
            ->selectRaw('downloads.revisable_type, downloads.revisable_id, count(*) as downloads')
            ->groupBy('downloads.revisable_type', 'downloads.revisable_id')
            ->orderByDesc('downloads')
            ->limit(5)
            ->get();

        $names = DownloadPresenter::entryNames($top);

        return [
            'total' => $total,
            'last_seven_days' => $lastSevenDays,
            'unique_boxes' => $uniqueBoxes,
            'top_entries' => $top->map(fn (Download $row) => [
                'entry_type' => $row->revisable_type,
                'entry_id' => $row->revisable_id,
                'entry_name' => $names[$row->revisable_type.':'.$row->revisable_id]['name'] ?? null,
                'downloads' => (int) $row->downloads,
            ])->all(),
        ];
    }
}
