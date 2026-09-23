<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Models\Customer;
use App\Models\Download;
use App\Models\Revision;
use App\Models\Script;
use App\Models\UnysisBox;
use App\Support\DateBucket;
use App\Support\DownloadPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The Marketplace dashboard.
 *
 * Every panel is gated on the `<resource>.view` permission that governs the page
 * it summarises, and a panel the Team Member cannot see is left out of the props
 * entirely rather than sent and hidden in the browser.
 */
class DashboardController extends Controller
{
    private const CHART_DAYS = 30;

    private const LIST_LIMIT = 10;

    public function index()
    {
        $user = request()->user();

        $can = fn (string $permission) => (bool) $user?->hasPermission($permission);

        $seesCatalogue = $can('scripts.view') || $can('ai_models.view');

        return Inertia::render('Dashboard', [
            'cards' => [
                'scripts' => $can('scripts.view') ? $this->entryCard(Script::class, 'script') : null,
                'aiModels' => $can('ai_models.view') ? $this->entryCard(AiModel::class, 'ai_model') : null,
                'customers' => $can('customers.view') ? $this->customerCard() : null,
                'unysisBoxes' => $can('unysis_boxes.view') ? $this->boxCard() : null,
                'downloads' => $can('downloads.view') ? $this->downloadCard() : null,
            ],
            'downloadsPerDay' => $can('downloads.view') ? $this->downloadsPerDay() : null,
            'latestRevisions' => $seesCatalogue ? $this->latestRevisions() : null,
            'recentBoxes' => $can('unysis_boxes.view') ? $this->recentBoxes() : null,
        ]);
    }

    /**
     * Catalogue entries of one type: how many have something released, out of how
     * many exist. Soft-deleted entries are excluded from both halves.
     *
     * @param  class-string<Model>  $model
     * @return array<string, int>
     */
    private function entryCard(string $model, string $morphAlias): array
    {
        $releasedIds = Revision::query()
            ->where('revisable_type', $morphAlias)
            ->where('status', Revision::STATUS_RELEASED)
            ->select('revisable_id');

        return [
            'released' => $model::query()->whereIn('id', $releasedIds)->count(),
            'total' => $model::query()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function customerCard(): array
    {
        return [
            'active' => Customer::query()->where('is_active', true)->count(),
            'total' => Customer::query()->count(),
        ];
    }

    /**
     * One grouped query for the three box statuses.
     *
     * @return array<string, int>
     */
    private function boxCard(): array
    {
        $byStatus = UnysisBox::query()
            ->selectRaw('status, count(*) as box_count')
            ->groupBy('status')
            ->pluck('box_count', 'status');

        return [
            'active' => (int) ($byStatus[UnysisBox::STATUS_ACTIVE] ?? 0),
            'pending' => (int) ($byStatus[UnysisBox::STATUS_PENDING] ?? 0),
            'blocked' => (int) ($byStatus[UnysisBox::STATUS_BLOCKED] ?? 0),
        ];
    }

    /**
     * Downloads over the last seven days, against the seven before them, so the
     * card can show which way the number is moving.
     *
     * @return array<string, int>
     */
    private function downloadCard(): array
    {
        $now = Carbon::now();

        $current = Download::query()
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->count();

        $previous = Download::query()
            ->where('created_at', '>=', $now->copy()->subDays(14))
            ->where('created_at', '<', $now->copy()->subDays(7))
            ->count();

        return [
            'last_seven_days' => $current,
            'previous_seven_days' => $previous,
            'delta' => $current - $previous,
        ];
    }

    /**
     * Downloads per day for the chart. One grouped query; the gaps are filled in
     * PHP so every day in the window has a point, including the zeroes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function downloadsPerDay(): array
    {
        $start = Carbon::today()->subDays(self::CHART_DAYS - 1);
        $bucket = DateBucket::expression('created_at');

        $counts = Download::query()
            ->where('created_at', '>=', $start)
            ->groupBy(DB::raw($bucket))
            ->orderBy(DB::raw($bucket))
            ->get([
                DB::raw("{$bucket} as day"),
                DB::raw('count(*) as downloads'),
            ])
            ->pluck('downloads', 'day');

        return collect(range(self::CHART_DAYS - 1, 0))
            ->map(function (int $daysAgo) use ($counts) {
                $date = Carbon::today()->subDays($daysAgo)->format('Y-m-d');

                return [
                    'date' => $date,
                    'downloads' => (int) ($counts[$date] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * The last ten Revisions uploaded, across both entry types.
     *
     * @return array<int, array<string, mixed>>
     */
    private function latestRevisions(): array
    {
        $revisions = Revision::query()
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $names = DownloadPresenter::entryNames($revisions);

        return $revisions->map(function (Revision $revision) use ($names) {
            $entry = $names[$revision->revisable_type.':'.$revision->revisable_id] ?? [];

            return [
                'id' => $revision->getKey(),
                'entry_type' => $revision->revisable_type,
                'entry_id' => $revision->revisable_id,
                'entry_name' => $entry['name'] ?? null,
                'machine_model' => $entry['machine_model'] ?? null,
                'number' => $revision->number,
                'status' => $revision->status,
                'uploaded_at' => $revision->created_at?->toIso8601String(),
                'uploaded_by' => $revision->uploader?->name,
            ];
        })->all();
    }

    /**
     * The ten UNYSIS Boxes that checked in most recently. A box that has never
     * been seen has no `last_seen_at` and is left out.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentBoxes(): array
    {
        return UnysisBox::query()
            ->with('customer:id,code,company')
            ->whereNotNull('last_seen_at')
            ->orderByDesc('last_seen_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (UnysisBox $box) => [
                'id' => $box->getKey(),
                'name' => $box->name,
                'motherboard_uuid' => $box->motherboard_uuid,
                'status' => $box->status,
                'last_seen_at' => $box->last_seen_at?->toIso8601String(),
                'customer_code' => $box->customer?->code,
                'customer_company' => $box->customer?->company,
            ])
            ->all();
    }
}
