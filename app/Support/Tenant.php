<?php

namespace App\Support;

use App\Models\Church;

/**
 * The church the current request (or console run) is working for. Set once per request by the
 * `church` middleware from the signed-in user. Every church-owned model filters and stamps itself
 * with it (see BelongsToChurch); with no church set (login, sign-up, platform console) nothing is filtered.
 */
class Tenant
{
    private static ?int $id = null;

    private static ?Church $church = null;

    public static function set(?int $id): void
    {
        self::$id = $id;
        self::$church = null;
        setPermissionsTeamId($id);
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function church(): ?Church
    {
        if (self::$id && (! self::$church || self::$church->id !== self::$id)) {
            self::$church = Church::find(self::$id);
        }

        return self::$church;
    }

    /** Run something for one church, then restore whatever was active before. */
    public static function run(int $id, callable $fn): mixed
    {
        $previous = self::$id;
        self::set($id);
        try {
            return $fn();
        } finally {
            self::set($previous);
        }
    }
}
