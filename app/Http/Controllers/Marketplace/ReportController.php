<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Download;
use App\Models\Revision;
use App\Models\UnysisBox;
use App\Support\DownloadPresenter;
use App\Support\DownloadQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Usage report — who downloaded what, over a date range.
 *
 * Every figure comes from a grouped query over `downloads`; nothing is counted
 * row by row in PHP, so the page costs a fixed handful of queries whatever the
 * size of the log. The SQL is kept to the dialect-neutral subset that both
 * SQLite (tests) and PostgreSQL (production) accept.
 */
class ReportController extends Controller
{
    /** Presets offered in the UI, in days. `all` means no lower bound. */
    public const RANGES = ['7', '30', '90', '365', 'all'];

    public const DEFAULT_RANGE = '30';

    public function usage(Request $request)
    {
        $this->authorizeView($request);

        $filters = $this->filters($request);

        return Inertia::render('Marketplace/Reports/Usage', [
            'filters' => $filters,
            'totals' => $this->totals($filters),
            'customers' => $this->byCustomer($filters),
            'entries' => $this->byEntry($filters),
        ]);
    }

    /**
     * One report section as CSV. `section=customers` or `section=entries`; the
     * rows are exactly the ones the page is showing for the same query string.
     */
    public function usageExport(Request $request): StreamedResponse
    {
        $this->authorizeView($request);

        $filters = $this->filters($request);
        $section = $request->string('section')->toString() === 'entries' ? 'entries' : 'customers';

        $rows = $section === 'entries' ? $this->byEntry($filters) : $this->byCustomer($filters);

        $columns = $section === 'entries'
            ? ['entry_type', 'entry_name', 'machine_model', 'machine_brand', 'downloads', 'distinct_customers', 'distinct_boxes', 'latest_released_revision', 'last_download_at']
            : ['customer_code', 'customer_company', 'active_boxes', 'boxes_downloaded', 'downloads', 'distinct_entries', 'last_download_at'];

        $filename = 'usage-'.$section.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? null, $columns));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('downloads.view'), 403);
    }

    /**
     * Date range and entry type, normalised.
     *
     * An explicit from/to pair wins; otherwise the preset sets the lower bound.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $range = $request->string('range')->toString();
        $range = in_array($range, self::RANGES, true) ? $range : self::DEFAULT_RANGE;

        $entryType = $request->string('entry_type')->toString();
        $entryType = in_array($entryType, DownloadQuery::ENTRY_TYPES, true) ? $entryType : null;

        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        if ($from === null && $to === null && $range !== 'all') {
            $from = Carbon::today()->subDays((int) $range - 1)->format('Y-m-d');
            $to = Carbon::today()->format('Y-m-d');
        }

        return [
            'range' => $range,
            'entry_type' => $entryType,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * The filtered Download set, with the Customer of each row resolved by join.
     *
     * A Download carries no customer_id, so it reaches its Customer through the
     * UNYSIS Box it came from or — for a web fetch with no box — through the
     * Customer User who made it. Both joins are left joins: a Team Member's web
     * download belongs to no Customer at all and must not vanish from the totals.
     */
    private function base(array $filters): Builder
    {
        return DownloadQuery::build($filters)
            ->leftJoin('unysis_boxes', 'unysis_boxes.id', '=', 'downloads.unysis_box_id')
            ->leftJoin('users', 'users.id', '=', 'downloads.user_id');
    }

    /** The SQL expression naming the Customer a Download belongs to. */
    private function customerExpression(): string
    {
        return 'coalesce(unysis_boxes.customer_id, users.customer_id)';
    }

    /**
     * A Download's entry identity as one value, for `count(distinct …)`.
     * `||` is the SQL standard concatenation operator; both dialects accept it.
     */
    private function entryExpression(): string
    {
        return "downloads.revisable_type || ':' || downloads.revisable_id";
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(array $filters): array
    {
        $row = $this->base($filters)
            ->selectRaw('count(*) as downloads')
            ->selectRaw('count(distinct '.$this->customerExpression().') as customers')
            ->selectRaw('count(distinct downloads.unysis_box_id) as boxes')
            ->selectRaw('count(distinct '.$this->entryExpression().') as entries')
            ->first();

        return [
            'downloads' => (int) ($row->downloads ?? 0),
            'customers' => (int) ($row->customers ?? 0),
            'boxes' => (int) ($row->boxes ?? 0),
            'entries' => (int) ($row->entries ?? 0),
        ];
    }

    /**
     * Section (a) — one row per Customer that downloaded in the range, plus every
     * Customer's current active-box count.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byCustomer(array $filters): array
    {
        $customerExpr = $this->customerExpression();

        $grouped = $this->base($filters)
            ->selectRaw($customerExpr.' as customer_key')
            ->selectRaw('count(*) as downloads')
            ->selectRaw('count(distinct downloads.unysis_box_id) as boxes_downloaded')
            ->selectRaw('count(distinct '.$this->entryExpression().') as distinct_entries')
            ->selectRaw('max(downloads.created_at) as last_download_at')
            ->groupByRaw($customerExpr)
            ->get()
            ->keyBy(fn (Download $row) => (string) $row->customer_key);

        // Active boxes are a property of the Customer today, not of the range,
        // so they come from their own grouped query over unysis_boxes.
        $activeBoxes = UnysisBox::query()
            ->where('status', UnysisBox::STATUS_ACTIVE)
            ->selectRaw('customer_id, count(*) as box_count')
            ->groupBy('customer_id')
            ->pluck('box_count', 'customer_id');

        $customers = Customer::query()
            ->orderBy('code')
            ->get(['id', 'code', 'company']);

        $rows = $customers->map(function (Customer $customer) use ($grouped, $activeBoxes) {
            $stats = $grouped->get((string) $customer->getKey());

            return [
                'customer_id' => $customer->getKey(),
                'customer_code' => $customer->code,
                'customer_company' => $customer->company,
                'active_boxes' => (int) ($activeBoxes[$customer->getKey()] ?? 0),
                'boxes_downloaded' => (int) ($stats->boxes_downloaded ?? 0),
                'downloads' => (int) ($stats->downloads ?? 0),
                'distinct_entries' => (int) ($stats->distinct_entries ?? 0),
                'last_download_at' => $this->iso($stats->last_download_at ?? null),
            ];
        })->values();

        // Downloads with no Customer at all — Team Members fetching from the admin
        // pages — are reported under their own row rather than dropped.
        $unattributed = $grouped->get('');
        $unattributed ??= $grouped->first(fn (Download $row) => $row->customer_key === null);

        if ($unattributed !== null) {
            $rows->push([
                'customer_id' => null,
                'customer_code' => null,
                'customer_company' => 'No Customer (internal)',
                'active_boxes' => 0,
                'boxes_downloaded' => (int) $unattributed->boxes_downloaded,
                'downloads' => (int) $unattributed->downloads,
                'distinct_entries' => (int) $unattributed->distinct_entries,
                'last_download_at' => $this->iso($unattributed->last_download_at),
            ]);
        }

        return $rows->sortByDesc('downloads')->values()->all();
    }

    /**
     * Section (b) — one row per catalogue entry downloaded in the range.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byEntry(array $filters): array
    {
        $grouped = $this->base($filters)
            ->selectRaw('downloads.revisable_type, downloads.revisable_id')
            ->selectRaw('count(*) as downloads')
            ->selectRaw('count(distinct '.$this->customerExpression().') as distinct_customers')
            ->selectRaw('count(distinct downloads.unysis_box_id) as distinct_boxes')
            ->selectRaw('max(downloads.created_at) as last_download_at')
            ->groupBy('downloads.revisable_type', 'downloads.revisable_id')
            ->get();

        if ($grouped->isEmpty()) {
            return [];
        }

        $names = DownloadPresenter::entryNames($grouped);
        $latest = $this->latestReleasedRevisions($grouped);

        return $grouped
            ->map(function (Download $row) use ($names, $latest) {
                $key = $row->revisable_type.':'.$row->revisable_id;
                $entry = $names[$key] ?? [];

                return [
                    'entry_type' => $row->revisable_type,
                    'entry_id' => $row->revisable_id,
                    'entry_name' => $entry['name'] ?? null,
                    'entry_deleted' => (bool) ($entry['deleted'] ?? true),
                    'machine_model' => $entry['machine_model'] ?? null,
                    'machine_brand' => $entry['machine_brand'] ?? null,
                    'downloads' => (int) $row->downloads,
                    'distinct_customers' => (int) $row->distinct_customers,
                    'distinct_boxes' => (int) $row->distinct_boxes,
                    'latest_released_revision' => $latest[$key] ?? null,
                    'last_download_at' => $this->iso($row->last_download_at),
                ];
            })
            ->sortByDesc('downloads')
            ->values()
            ->all();
    }

    /**
     * Highest released Revision number per entry, in one grouped query.
     *
     * @param  Collection<int, Download>  $rows
     * @return array<string, int>
     */
    private function latestReleasedRevisions(Collection $rows): array
    {
        $ids = $rows->pluck('revisable_id')->unique()->values()->all();

        return Revision::query()
            ->where('status', Revision::STATUS_RELEASED)
            ->whereIn('revisable_id', $ids)
            ->groupBy('revisable_type', 'revisable_id')
            ->get([
                'revisable_type',
                'revisable_id',
                DB::raw('max(number) as latest_number'),
            ])
            ->mapWithKeys(fn ($row) => [
                $row->revisable_type.':'.$row->revisable_id => (int) $row->latest_number,
            ])
            ->all();
    }

    /**
     * `max(created_at)` comes back as a driver-shaped string, not a Carbon, so it
     * is normalised here rather than relied on being cast.
     */
    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
