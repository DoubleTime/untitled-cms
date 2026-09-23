<?php

namespace App\Support;

use App\Models\AiModel;
use App\Models\Download;
use App\Models\Script;
use App\Models\UnysisBox;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The one filter builder for the Download log.
 *
 * The log index, its CSV export and the Usage report all narrow the same table
 * the same way, so the request parsing and the query construction live here
 * rather than being copied into each controller.
 */
class DownloadQuery
{
    public const ENTRY_TYPES = ['script', 'ai_model'];

    /**
     * Normalise the query string into the filter array the pages echo back.
     *
     * Unknown values are dropped rather than passed through, so a hand-edited URL
     * can never reach the query builder.
     *
     * @return array<string, string|null>
     */
    public static function filters(Request $request): array
    {
        $source = $request->string('source')->toString();
        $entryType = $request->string('entry_type')->toString();

        return [
            'source' => in_array($source, [Download::SOURCE_API, Download::SOURCE_WEB], true) ? $source : null,
            'entry_type' => in_array($entryType, self::ENTRY_TYPES, true) ? $entryType : null,
            'customer_id' => $request->string('customer_id')->toString() ?: null,
            'unysis_box_id' => $request->string('unysis_box_id')->toString() ?: null,
            'user_id' => $request->string('user_id')->toString() ?: null,
            'q' => trim($request->string('q')->toString()) ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    public static function build(array $filters): Builder
    {
        // Every column is table-qualified: the Usage report joins `unysis_boxes`
        // and `users` onto this builder, and both carry a `created_at` of their own.
        return Download::query()
            ->when($filters['source'] ?? null, fn (Builder $q, $source) => $q->where('downloads.source', $source))
            ->when($filters['entry_type'] ?? null, fn (Builder $q, $type) => $q->where('downloads.revisable_type', $type))
            ->when($filters['unysis_box_id'] ?? null, fn (Builder $q, $id) => $q->where('downloads.unysis_box_id', $id))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('downloads.user_id', $id))
            // A Download carries no customer_id: it belongs to a Customer through
            // the UNYSIS Box it came from, or — for a web fetch with no box — through
            // the Customer User who made it.
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where(
                fn (Builder $inner) => $inner
                    ->whereIn('downloads.unysis_box_id', UnysisBox::query()->where('customer_id', $id)->select('id'))
                    ->orWhereIn('downloads.user_id', User::query()->where('customer_id', $id)->select('id'))
            ))
            ->when($filters['q'] ?? null, fn (Builder $q, $term) => self::applyEntrySearch($q, $term))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('downloads.created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('downloads.created_at', '<=', $to));
    }

    /**
     * Entry search resolves the matching entry ids first, per entry type, and
     * filters the polymorphic column on those — a join is not possible across two
     * tables behind one morph column.
     */
    public static function applyEntrySearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $scriptIds = Script::withTrashed()->where('name', 'like', $like)->pluck('id')->all();
        $aiModelIds = AiModel::withTrashed()->where('name', 'like', $like)->pluck('id')->all();

        return $query->where(function (Builder $inner) use ($scriptIds, $aiModelIds) {
            $inner->whereRaw('1 = 0');

            if ($scriptIds !== []) {
                $inner->orWhere(fn (Builder $q) => $q
                    ->where('downloads.revisable_type', 'script')
                    ->whereIn('downloads.revisable_id', $scriptIds));
            }

            if ($aiModelIds !== []) {
                $inner->orWhere(fn (Builder $q) => $q
                    ->where('downloads.revisable_type', 'ai_model')
                    ->whereIn('downloads.revisable_id', $aiModelIds));
            }
        });
    }
}
