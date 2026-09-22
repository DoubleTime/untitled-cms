<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\AiBox;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\Download;
use App\Models\FlowchartScript;
use App\Models\User;
use App\Support\DownloadPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The Download log — every recorded fetch of a Revision file, from RPA-TOOL
 * (`api`) and from Team Members on the admin pages (`web`).
 *
 * Download rows are append-only and are never deleted, not even when the entry
 * or the Revision they point at is hard deleted, so this is the durable record
 * of what left the Marketplace.
 */
class DownloadController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request)
    {
        $this->authorizeView($request);

        $filters = $this->filters($request);

        $downloads = $this->query($filters)
            ->with(['user:id,name', 'revision:id,number', 'aiBox:id,name,motherboard_uuid'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
            'aiBoxes' => AiBox::query()
                ->with('customer:id,company')
                ->orderBy('motherboard_uuid')
                ->get(['id', 'name', 'motherboard_uuid', 'customer_id']),
            'users' => User::query()
                ->whereIn('id', Download::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'customer_id']),
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('downloads.view'), 403);
    }

    /**
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $source = $request->string('source')->toString();
        $entryType = $request->string('entry_type')->toString();

        return [
            'source' => in_array($source, [Download::SOURCE_API, Download::SOURCE_WEB], true) ? $source : null,
            'entry_type' => in_array($entryType, ['flowchart_script', 'ai_model'], true) ? $entryType : null,
            'customer_id' => $request->string('customer_id')->toString() ?: null,
            'ai_box_id' => $request->string('ai_box_id')->toString() ?: null,
            'user_id' => $request->string('user_id')->toString() ?: null,
            'q' => trim($request->string('q')->toString()) ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    private function query(array $filters): Builder
    {
        return Download::query()
            ->when($filters['source'], fn (Builder $q, $source) => $q->where('source', $source))
            ->when($filters['entry_type'], fn (Builder $q, $type) => $q->where('revisable_type', $type))
            ->when($filters['ai_box_id'], fn (Builder $q, $id) => $q->where('ai_box_id', $id))
            ->when($filters['user_id'], fn (Builder $q, $id) => $q->where('user_id', $id))
            // A Download carries no customer_id: it belongs to a Customer through
            // the AI Box it came from, or — for a web fetch with no box — through
            // the Customer User who made it.
            ->when($filters['customer_id'], fn (Builder $q, $id) => $q->where(
                fn (Builder $inner) => $inner
                    ->whereIn('ai_box_id', AiBox::query()->where('customer_id', $id)->select('id'))
                    ->orWhereIn('user_id', User::query()->where('customer_id', $id)->select('id'))
            ))
            ->when($filters['q'], fn (Builder $q, $term) => $this->applyEntrySearch($q, $term))
            ->when($filters['from'], fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn (Builder $q, $to) => $q->whereDate('created_at', '<=', $to));
    }

    /**
     * Entry search resolves the matching entry ids first, per entry type, and
     * filters the polymorphic column on those — a join is not possible across two
     * tables behind one morph column.
     */
    private function applyEntrySearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $scriptIds = FlowchartScript::withTrashed()->where('name', 'like', $like)->pluck('id')->all();
        $aiModelIds = AiModel::withTrashed()->where('name', 'like', $like)->pluck('id')->all();

        return $query->where(function (Builder $inner) use ($scriptIds, $aiModelIds) {
            $inner->whereRaw('1 = 0');

            if ($scriptIds !== []) {
                $inner->orWhere(fn (Builder $q) => $q
                    ->where('revisable_type', 'flowchart_script')
                    ->whereIn('revisable_id', $scriptIds));
            }

            if ($aiModelIds !== []) {
                $inner->orWhere(fn (Builder $q) => $q
                    ->where('revisable_type', 'ai_model')
                    ->whereIn('revisable_id', $aiModelIds));
            }
        });
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
        $total = $this->query($filters)->count();

        $lastSevenDays = $this->query($filters)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $uniqueBoxes = $this->query($filters)
            ->whereNotNull('ai_box_id')
            ->distinct()
            ->count('ai_box_id');

        $top = $this->query($filters)
            ->selectRaw('revisable_type, revisable_id, count(*) as downloads')
            ->groupBy('revisable_type', 'revisable_id')
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
