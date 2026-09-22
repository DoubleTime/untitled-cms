<?php

namespace App\Policies;

use App\Models\Script;
use App\Models\User;

class ScriptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('scripts.view');
    }

    public function view(User $user, Script $script): bool
    {
        return $user->hasPermission('scripts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('scripts.create');
    }

    public function update(User $user, Script $script): bool
    {
        return $user->hasPermission('scripts.edit');
    }

    public function delete(User $user, Script $script): bool
    {
        return $user->hasPermission('scripts.delete');
    }

    public function restore(User $user, Script $script): bool
    {
        return $user->hasPermission('scripts.delete');
    }

    /** Upload a new Revision. */
    public function upload(User $user, Script $script): bool
    {
        return $user->hasPermission('scripts.upload');
    }

    /** Release or deprecate a Revision. */
    public function release(User $user, Script $script): bool
    {
        return $user->hasPermission('scripts.release');
    }

    /** Purge the entry or a Revision, files included. */
    public function hardDelete(User $user, Script $script): bool
    {
        return $user->hasPermission('scripts.hard_delete');
    }
}
