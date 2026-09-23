<?php

namespace App\Services\Marketplace;

use App\Models\Customer;
use App\Models\Download;
use App\Models\Revision;
use App\Models\UnysisBox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Usage report — who downloaded what, over a date range.
 *
 * Every figure comes from a grouped query over `downloads`; nothing is counted
 * row by row in PHP, so a report costs a fixed handful of queries whatever the
 * size of the log. The SQL is kept to the dialect-neutral subset that both SQLite
 * (tests) and PostgreSQL (production) accept: `count(distinct …)`, `||`
 * concatenation and `coalesce` are all in it.
 *
 * Attribution is the awkward part. A Download carries no `customer_id`: it
 * reaches its Customer through the UNYSIS Box it came from, or — for a web fetch
 * with no box — through the Customer User who made it. Both joins are *left*
 * joins, because a Team Member's web download belongs to no Customer at all and
 * must not vanish from the totals.
 */
class UsageReportService
{
    /** Presets offered in the UI, in days. `all` means no lower bound. */
    public const RANGES = ['7', '30', '90', '365', 'all'];

    public const DEFAULT_RANGE = '30';

    /** The label the by-Customer section gives to downloads with no Customer. */
    public const NO_CUSTOMER_LABEL = 'No Customer (internal)';

    /** @var array<int, string> */
    public const CUSTOMER_COLUMNS = [
        'customer_code', 'customer_company', 'active_boxes', 'boxes_downloaded',
        'downloads', 'distinct_entries', 'last_download_at',
    ];

    /** @var array<int, string> */
    public const ENTRY_COLUMNS = [
        'entry_type', 'entry_name', 'machine_model', 'machine_brand', 'downloads',
        'distinct_customers', 'distinct_boxes', 'latest_released_revision', 'last_download_at',
    ];

    /**
     * Date range and entry type, normalised. An explicit from/to pair wins;
     * otherwise the preset sets the lower bound. Unknown values are dropped, so a
     * hand-edited URL never reaches the query builder.
     *
     * @return array<string, string|null>
     */
    public function filters(Request $request): array
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
     * The totals strip: downloads, Customers, UNYSIS Boxes and distinct entries,
     * from one query.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    public function totals(array $filters): array
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
     * Section (a) — one row per Customer that **downloaded in the range**, plus a
     * single "No Customer (internal)" row when the range contains web downloads
     * made without an UNYSIS Box.
     *
     * A Customer that downloaded nothing in the range is not a row: the report is
     * about usage, and listing every Customer in the database with zeroes buries
     * the ones that matter. `active_boxes` is still a property of the Customer
     * *today* rather than of the range, so it comes from its own grouped query
     * over `unysis_boxes`.
     *
     * @param  array<string, string|null>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function byCustomer(array $filters): array
    {
        $customerExpr = $this->customerExpression();

        $grouped = $this->base($filters)
            ->selectRaw($customerExpr.' as customer_key')
            ->selectRaw('count(*) as downloads')
            ->selectRaw('count(distinct downloads.unysis_box_id) as boxes_downloaded')
            ->selectRaw('count(distinct '.$this->entryExpression().') as distinct_entries')
            ->selectRaw('max(downloads.created_at) as last_download_at')
            ->groupByRaw($customerExpr)
            ->get();

        // Rows whose coalesce(...) came back empty are the unattributed ones: a
        // Team Member fetching from the admin pages belongs to no Customer.
        $attributed = $grouped->filter(fn (Download $row) => (string) $row->customer_key !== '');
        $unattributed = $grouped->first(fn (Download $row) => (string) $row->customer_key === '');

        $activeBoxes = UnysisBox::query()
            ->where('status', UnysisBox::STATUS_ACTIVE)
            ->selectRaw('customer_id, count(*) as box_count')
            ->groupBy('customer_id')
            ->pluck('box_count', 'customer_id');

        // Only the Customers that actually appear in the grouped result are looked
        // up, so the query is bounded by the report rather than by the table.
        $customers = Customer::query()
            ->whereIn('id', $attributed->pluck('customer_key')->all())
            ->orderBy('code')
            ->get(['id', 'code', 'company'])
            ->keyBy(fn (Customer $customer) => (string) $customer->getKey());

        $rows = $attributed->map(function (Download $stats) use ($customers, $activeBoxes) {
            $key = (string) $stats->customer_key;
            $customer = $customers->get($key);

            return [
                'customer_id' => $customer?->getKey() ?? $key,
                'customer_code' => $customer?->code,
                'customer_company' => $customer?->company,
                'active_boxes' => (int) ($activeBoxes[$key] ?? 0),
                'boxes_downloaded' => (int) $stats->boxes_downloaded,
                'downloads' => (int) $stats->downloads,
                'distinct_entries' => (int) $stats->distinct_entries,
                'last_download_at' => $this->iso($stats->last_download_at),
            ];
        })->values();

        if ($unattributed !== null) {
            $rows->push([
                'customer_id' => null,
                'customer_code' => null,
                'customer_company' => self::NO_CUSTOMER_LABEL,
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
     * @param  array<string, string|null>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function byEntry(array $filters): array
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
     * One report section as rows and the CSV header that goes with them.
     *
     * @param  array<string, string|null>  $filters
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    public function section(string $section, array $filters): array
    {
        return $section === 'entries'
            ? ['columns' => self::ENTRY_COLUMNS, 'rows' => $this->byEntry($filters)]
            : ['columns' => self::CUSTOMER_COLUMNS, 'rows' => $this->byCustomer($filters)];
    }

    /**
     * One CSV line: the named columns of a row, in order, with anything missing
     * written as an empty cell.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columns
     * @return array<int, mixed>
     */
    public function csvRow(array $row, array $columns): array
    {
        return array_map(fn (string $column) => $row[$column] ?? null, $columns);
    }

    /**
     * The filtered Download set with the Customer of each row resolved by join.
     *
     * @param  array<string, string|null>  $filters
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
