<?php

namespace App\Services\Marketplace;

use App\Models\AiModel;
use App\Models\Download;
use App\Models\Revision;
use App\Models\Script;
use App\Models\UnysisBox;
use Illuminate\Support\Collection;

/**
 * Derives what an UNYSIS Box currently has installed.
 *
 * "Installed" is not stored anywhere (see docs/marketplace-plan.md): it is the
 * **latest Download of a catalogue entry by this box**. An entry appears here as
 * soon as the box has ever fetched it, whatever the Revision's status now — a box
 * that only ever pulled a Revision since deprecated is still running it, and that
 * is exactly what a Team Member needs to see.
 *
 * ## Why the latest Download is reduced in PHP
 *
 * The natural SQL is "the row with the greatest created_at per (revisable_type,
 * revisable_id)", which needs either a window function or a self-join on a grouped
 * max — and `max(id)` is *not* the latest row here, because the primary keys are
 * ULIDs and are only lexically sortable when generated in order, which a restored
 * or back-dated row breaks. Rather than carry two dialect-specific queries for
 * SQLite (tests) and PostgreSQL (production), this reads the box's own Download
 * log — bounded by one UNYSIS Box, so tens to a few hundred rows — ordered newest
 * first and keeps the first row seen per entry.
 *
 * The whole derivation is five queries regardless of how many entries are
 * installed: the Download log, the two entry tables, and the released Revisions
 * of each entry type.
 */
class UnysisBoxInstalledService
{
    /**
     * One row per catalogue entry this box has ever downloaded, newest download first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBox(UnysisBox $box): array
    {
        $downloads = Download::query()
            ->where('unysis_box_id', $box->getKey())
            ->with(['revision:id,number,status'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'revision_id', 'revisable_type', 'revisable_id', 'created_at']);

        /** @var array<string, Download> $latest */
        $latest = [];

        foreach ($downloads as $download) {
            $key = $download->revisable_type.':'.$download->revisable_id;

            if (! isset($latest[$key])) {
                $latest[$key] = $download;
            }
        }

        if ($latest === []) {
            return [];
        }

        $entries = $this->entriesFor(collect($latest));
        $latestReleased = $this->latestReleasedNumbers(collect($latest));

        $rows = [];

        foreach ($latest as $key => $download) {
            $entry = $entries[$key] ?? null;

            $installedNumber = $download->revision?->number;
            $latestNumber = $latestReleased[$key] ?? null;

            $rows[] = [
                'key' => $key,
                'entry_type' => $download->revisable_type,
                'entry_id' => $download->revisable_id,
                'entry_name' => $entry?->name,
                'entry_deleted' => $entry === null || $entry->deleted_at !== null,
                'machine_model' => $entry?->machineModel?->name,
                'machine_brand' => $entry?->machineModel?->machineBrand?->name,
                'installed_number' => $installedNumber,
                'installed_status' => $download->revision?->status,
                'downloaded_at' => optional($download->created_at)->toIso8601String(),
                'latest_released_number' => $latestNumber,
                'outdated' => $installedNumber !== null
                    && $latestNumber !== null
                    && $installedNumber < $latestNumber,
            ];
        }

        return $rows;
    }

    /**
     * The catalogue entries behind the keys, keyed the same way. Soft-deleted
     * entries are included so an installed row never disappears silently.
     *
     * @param  Collection<string, Download>  $latest
     * @return array<string, Script|AiModel>
     */
    private function entriesFor(Collection $latest): array
    {
        $out = [];

        foreach ($this->idsByType($latest) as $type => $ids) {
            $model = $this->modelFor($type);

            if ($model === null) {
                continue;
            }

            $rows = $model::withTrashed()
                ->whereIn('id', $ids)
                ->with(['machineModel:id,name,machine_brand_id', 'machineModel.machineBrand:id,name'])
                ->get(['id', 'name', 'machine_model_id', 'deleted_at']);

            foreach ($rows as $row) {
                $out[$type.':'.$row->getKey()] = $row;
            }
        }

        return $out;
    }

    /**
     * The highest released Revision number per entry, keyed the same way.
     *
     * @param  Collection<string, Download>  $latest
     * @return array<string, int>
     */
    private function latestReleasedNumbers(Collection $latest): array
    {
        $out = [];

        foreach ($this->idsByType($latest) as $type => $ids) {
            $revisions = Revision::query()
                ->where('revisable_type', $type)
                ->whereIn('revisable_id', $ids)
                ->where('status', Revision::STATUS_RELEASED)
                ->orderByDesc('number')
                ->get(['revisable_type', 'revisable_id', 'number']);

            foreach ($revisions as $revision) {
                $key = $type.':'.$revision->revisable_id;

                // Ordered by number desc, so the first one seen is the highest.
                $out[$key] ??= (int) $revision->number;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<string, Download>  $latest
     * @return array<string, array<int, string>>
     */
    private function idsByType(Collection $latest): array
    {
        return $latest
            ->groupBy(fn (Download $download) => $download->revisable_type)
            ->map(fn (Collection $group) => $group->pluck('revisable_id')->unique()->values()->all())
            ->all();
    }

    /**
     * @return class-string<Script>|class-string<AiModel>|null
     */
    private function modelFor(string $type): ?string
    {
        return match ($type) {
            'script', Script::class => Script::class,
            'ai_model', AiModel::class => AiModel::class,
            default => null,
        };
    }
}
