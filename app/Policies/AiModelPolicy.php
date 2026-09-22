<?php

namespace App\Policies;

use App\Models\AiModel;
use App\Models\User;

class AiModelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('ai_models.view');
    }

    public function view(User $user, AiModel $aiModel): bool
    {
        return $user->hasPermission('ai_models.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('ai_models.create');
    }

    public function update(User $user, AiModel $aiModel): bool
    {
        return $user->hasPermission('ai_models.edit');
    }

    public function delete(User $user, AiModel $aiModel): bool
    {
        return $user->hasPermission('ai_models.delete');
    }

    public function restore(User $user, AiModel $aiModel): bool
    {
        return $user->hasPermission('ai_models.delete');
    }

    /** Upload a new Revision. */
    public function upload(User $user, AiModel $aiModel): bool
    {
        return $user->hasPermission('ai_models.upload');
    }

    /** Release or deprecate a Revision. */
    public function release(User $user, AiModel $aiModel): bool
    {
        return $user->hasPermission('ai_models.release');
    }

    /** Purge the entry or a Revision, files included. */
    public function hardDelete(User $user, AiModel $aiModel): bool
    {
        return $user->hasPermission('ai_models.hard_delete');
    }
}
