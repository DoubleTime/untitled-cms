<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DateBucket
{
    /**
     * A SQL expression bucketing a timestamp column to 'YYYY-MM-DD'.
     */
    public static function expression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', {$column})"
            : "to_char({$column}, 'YYYY-MM-DD')";
    }
}
