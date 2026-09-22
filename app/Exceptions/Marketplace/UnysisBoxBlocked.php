<?php

namespace App\Exceptions\Marketplace;

use App\Models\UnysisBox;
use RuntimeException;

/**
 * A blocked UNYSIS Box is refused at login and on every subsequent authenticated
 * request, so blocking one cuts its access off immediately even while it still
 * holds a live token.
 */
class UnysisBoxBlocked extends RuntimeException
{
    public static function for(UnysisBox $box): self
    {
        return new self(
            "This UNYSIS Box (motherboard UUID {$box->motherboard_uuid}) has been blocked. Contact UNYSIS support."
        );
    }
}
