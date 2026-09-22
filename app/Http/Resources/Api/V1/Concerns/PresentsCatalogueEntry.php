<?php

namespace App\Http\Resources\Api\V1\Concerns;

use App\Http\Resources\Api\V1\RevisionSummaryResource;
use App\Models\Revision;
use Illuminate\Support\Collection;

/**
 * The fields Scripts and AI Models present identically to RPA-TOOL.
 *
 * `latest_revision` is read from the eager-loaded `revisions` relation rather than
 * queried per row: the catalogue controllers load it already narrowed to the
 * statuses the API exposes (released + deprecated) and ordered by number desc.
 */
trait PresentsCatalogueEntry
{
    /** @return array<string, mixed> */
    protected function commonEntryFields(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'machine_model' => $this->whenLoaded('machineModel', fn () => $this->machineModel === null ? null : [
                'id' => $this->machineModel->id,
                'name' => $this->machineModel->name,
                'brand' => $this->machineModel->relationLoaded('machineBrand') && $this->machineModel->machineBrand
                    ? [
                        'id' => $this->machineModel->machineBrand->id,
                        'name' => $this->machineModel->machineBrand->name,
                    ]
                    : null,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'company' => $this->customer->company,
            ]),
            'latest_revision' => $this->latestReleasedFromLoaded(),
            'revisions_count' => (int) ($this->revisions_count ?? 0),
            'downloads_count' => (int) ($this->downloads_count ?? 0),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * The visible Revisions (released + deprecated), newest first.
     *
     * @return Collection<int, Revision>
     */
    protected function visibleRevisions()
    {
        return $this->relationLoaded('revisions')
            ? $this->revisions
            : $this->revisions()
                ->whereIn('status', [Revision::STATUS_RELEASED, Revision::STATUS_DEPRECATED])
                ->orderByDesc('number')
                ->get();
    }

    protected function latestReleasedFromLoaded(): ?array
    {
        $latest = $this->visibleRevisions()
            ->first(fn (Revision $revision) => $revision->status === Revision::STATUS_RELEASED);

        return $latest === null ? null : (new RevisionSummaryResource($latest))->resolve();
    }
}
