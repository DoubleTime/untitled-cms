<?php

namespace App\Support;

use App\Models\AiModel;
use App\Models\Download;
use App\Models\Script;
use Illuminate\Support\Collection;

/**
 * Shapes Download rows for the admin pages.
 *
 * A Download points at its catalogue entry polymorphically, but a `morphTo`
 * eager load will not reach a soft-deleted entry — and hard-deleting an entry
 * deliberately keeps its Download rows. So the entry names for a page of rows are
 * resolved here with one `withTrashed()` query per entry type, and a row whose
 * entry is gone still renders.
 */
class DownloadPresenter
{
    /**
     * Entry names for a set of Download rows, keyed `"{morph alias}:{id}"`.
     *
     * @param  Collection<int, Download>  $downloads
     * @return array<string, array<string, mixed>>
     */
    public static function entryNames(Collection $downloads): array
    {
        $out = [];

        $byType = $downloads
            ->groupBy(fn (Download $download) => (string) $download->revisable_type)
            ->map(fn (Collection $group) => $group->pluck('revisable_id')->unique()->values()->all());

        foreach ($byType as $type => $ids) {
            $model = match ($type) {
                'script', Script::class => Script::class,
                'ai_model', AiModel::class => AiModel::class,
                default => null,
            };

            if ($model === null || $ids === []) {
                continue;
            }

            foreach ($model::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'deleted_at']) as $entry) {
                $out[$type.':'.$entry->getKey()] = [
                    'name' => $entry->name,
                    'deleted' => $entry->deleted_at !== null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $names
     * @return array<string, mixed>
     */
    public static function present(Download $download, array $names): array
    {
        $key = $download->revisable_type.':'.$download->revisable_id;
        $entry = $names[$key] ?? null;

        return [
            'id' => $download->getKey(),
            'created_at' => $download->created_at?->toIso8601String(),
            'source' => $download->source,
            'ip' => $download->ip,
            'entry_type' => $download->revisable_type,
            'entry_id' => $download->revisable_id,
            'entry_name' => $entry['name'] ?? null,
            'entry_deleted' => (bool) ($entry['deleted'] ?? true),
            'user' => $download->user
                ? ['id' => $download->user->getKey(), 'name' => $download->user->name]
                : null,
            'unysis_box' => $download->relationLoaded('unysisBox') && $download->unysisBox
                ? [
                    'id' => $download->unysisBox->getKey(),
                    'name' => $download->unysisBox->name,
                    'motherboard_uuid' => $download->unysisBox->motherboard_uuid,
                ]
                : null,
            'revision' => $download->revision
                ? ['id' => $download->revision->getKey(), 'number' => $download->revision->number]
                : null,
        ];
    }
}
