<?php

namespace App\Policies;

use App\Models\FlowchartScript;
use App\Models\User;

class FlowchartScriptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('scripts.view');
    }

    public function view(User $user, FlowchartScript $script): bool
    {
        return $user->hasPermission('scripts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('scripts.create');
    }

    public function update(User $user, FlowchartScript $script): bool
    {
        return $user->hasPermission('scripts.edit');
    }

    public function delete(User $user, FlowchartScript $script): bool
    {
        return $user->hasPermission('scripts.delete');
    }

    public function restore(User $user, FlowchartScript $script): bool
    {
        return $user->hasPermission('scripts.delete');
    }

    /** Upload a new Revision. */
    public function upload(User $user, FlowchartScript $script): bool
    {
        return $user->hasPermission('scripts.upload');
    }

    /** Release or deprecate a Revision. */
    public function release(User $user, FlowchartScript $script): bool
    {
        return $user->hasPermission('scripts.release');
    }

    /** Purge the entry or a Revision, files included. */
    public function hardDelete(User $user, FlowchartScript $script): bool
    {
        return $user->hasPermission('scripts.hard_delete');
    }
}
