<?php

namespace App\Support;

/**
 * The church's own calendar date. The app runs in UTC, so between midnight and 3am in Kampala
 * `today` is still yesterday; validation of "not in the future" must use the local date instead.
 */
class ChurchDate
{
    public static function today(): string
    {
        return now(config('church.timezone', config('app.timezone')))->toDateString();
    }

    /** Validation rule: on or before the church's today. */
    public static function notFuture(): string
    {
        return 'before_or_equal:'.self::today();
    }
}
