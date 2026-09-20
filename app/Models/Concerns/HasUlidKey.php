<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * ULID string primary keys.
 *
 * Wraps HasUlids in one place so the key strategy can change without
 * touching 16 models. ULIDs are used because they are strings — keeping
 * the frontend's `id: string` contract intact after the move off
 * MongoDB ObjectIds — and because they sort lexically by creation time.
 */
trait HasUlidKey
{
    use HasUlids;
}
