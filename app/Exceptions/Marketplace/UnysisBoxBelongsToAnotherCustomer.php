<?php

namespace App\Exceptions\Marketplace;

use RuntimeException;

/**
 * An UNYSIS Box is identified by the motherboard UUID it reports, and it belongs to
 * exactly one Customer from the moment it first signs in (docs/adr/0002). A
 * Customer User from a different Customer presenting the same UUID is refused;
 * the box is never silently reassigned.
 */
class UnysisBoxBelongsToAnotherCustomer extends RuntimeException
{
    public static function for(string $motherboardUuid): self
    {
        return new self(
            "This UNYSIS Box (motherboard UUID {$motherboardUuid}) is already registered to a different Customer. "
            .'Ask UNYSIS to reassign it before signing in from this account.'
        );
    }
}
