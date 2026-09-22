<?php

namespace App\Exceptions\Marketplace;

use App\Models\Revision;
use RuntimeException;

/**
 * A Revision Status change that the lifecycle does not allow.
 *
 * The lifecycle is one-way: draft → released → deprecated. There is no path
 * back to draft, and a deprecated Revision is final.
 */
class InvalidRevisionTransition extends RuntimeException
{
    public static function for(Revision $revision, string $target): self
    {
        return new self(
            "Revision {$revision->number} is {$revision->status} and cannot be {$target}."
        );
    }
}
