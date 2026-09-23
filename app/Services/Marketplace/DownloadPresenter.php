<?php

namespace App\Services\Marketplace;

use App\Models\Download;
use App\Support\CatalogueEntryType;
use Illuminate\Database\Eloquent\Model;
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
     * Entry names — with the Machine Model and Brand the entry targets — for a set
     * of Download rows, keyed `"{morph alias}:{id}"`.
     *
     * Any row carrying `revisable_type` and `revisable_id` works — Download rows,
     * grouped report rows, and Revision rows all pass through unchanged.
     *
     * @param  Collection<int, Model>  $downloads
     * @return array<string, array<string, mixed>>
     */
    public static function entryNames(Collection $downloads): array
    {
        $out = [];

        $byType = $downloads
            ->groupBy(fn ($row) => (string) $row->revisable_type)
            ->map(fn (Collection $group) => $group->pluck('revisable_id')->unique()->values()->all());

        foreach ($byType as $type => $ids) {
            $model = CatalogueEntryType::modelClass($type);

            if ($model === null || $ids === []) {
                continue;
            }

            $entries = $model::withTrashed()
                ->with('machineModel.machineBrand')
                ->whereIn('id', $ids)
                ->get(['id', 'name', 'machine_model_id', 'deleted_at']);

            foreach ($entries as $entry) {
                $out[$type.':'.$entry->getKey()] = [
                    'name' => $entry->name,
                    'deleted' => $entry->deleted_at !== null,
                    'machine_model' => $entry->machineModel?->name,
                    'machine_brand' => $entry->machineModel?->machineBrand?->name,
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
