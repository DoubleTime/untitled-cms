<?php

namespace App\Exceptions\Marketplace;

use App\Models\AiBox;
use RuntimeException;

/**
 * A blocked AI Box is refused at login and on every subsequent authenticated
 * request, so blocking one cuts its access off immediately even while it still
 * holds a live token.
 */
class AiBoxBlocked extends RuntimeException
{
    public static function for(AiBox $box): self
    {
        return new self(
            "This AI Box (motherboard UUID {$box->motherboard_uuid}) has been blocked. Contact UNYSIS support."
        );
    }
}
