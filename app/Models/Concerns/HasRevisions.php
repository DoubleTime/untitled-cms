<?php

namespace App\Models\Concerns;

use App\Models\Download;
use App\Models\Revision;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared Revision/Download behaviour for the two catalogue entry types
 * (Script and AI Model). Revisions are polymorphic so both share one
 * implementation while staying separate entities in the UI and the API.
 */
trait HasRevisions
{
    public function revisions(): MorphMany
    {
        return $this->morphMany(Revision::class, 'revisable')->orderByDesc('number');
    }

    /**
     * The highest-numbered released Revision, or null when none has been released.
     * Draft and deprecated Revisions are ignored.
     */
    public function latestReleasedRevision(): ?Revision
    {
        return $this->revisions()
            ->where('status', Revision::STATUS_RELEASED)
            ->orderByDesc('number')
            ->first();
    }

    public function downloads(): MorphMany
    {
        return $this->morphMany(Download::class, 'revisable');
    }
}
