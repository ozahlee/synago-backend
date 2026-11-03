<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * Makes `exists:` and `unique:` validation rules church-aware: a pledge number only has to be unique
 * within the church, and a memberId must belong to the church making the request.
 * User email addresses stay unique across the whole platform (they are the login).
 */
class TenantPresenceVerifier extends DatabasePresenceVerifier
{
    /** @var array<string, bool> */
    private static array $hasChurch = [];

    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        return $this->scoped($collection, $column, fn () => parent::getCount($collection, $column, $value, $excludeId, $idColumn, $extra), $extra, fn ($x) => parent::getCount($collection, $column, $value, $excludeId, $idColumn, $x));
    }

    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        return $this->scoped($collection, $column, fn () => parent::getMultiCount($collection, $column, $values, $extra), $extra, fn ($x) => parent::getMultiCount($collection, $column, $values, $x));
    }

    private function scoped(string $table, string $column, callable $plain, array $extra, callable $withExtra)
    {
        $id = Tenant::id();
        if (! $id || ($table === 'users' && $column === 'email') || ! $this->hasChurchColumn($table)) {
            return $plain();
        }

        return $withExtra($extra + ['church_id' => (string) $id]);
    }

    private function hasChurchColumn(string $table): bool
    {
        return self::$hasChurch[$table] ??= Schema::hasColumn($table, 'church_id');
    }
}
