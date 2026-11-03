<?php

namespace App\Support;

use App\Models\Position;
use App\Models\User;

/**
 * Scoped access for position-holders. A user linked to a member who currently holds a position over a
 * group (Sunday school class, ministry, cell group, project) may work with that group only.
 */
final class Leadership
{
    /** @return array<string, list<int>> scope_type => ids the user currently leads */
    public static function scopes(User $user): array
    {
        // Read the raw attribute: a just-created User may not have loaded member_id yet.
        $memberId = $user->getAttributes()['member_id'] ?? null;
        if (! $memberId) {
            return [];
        }

        return Position::current()->where('member_id', $memberId)->whereNotNull('scope_id')->get(['scope_type', 'scope_id'])
            ->groupBy('scope_type')->map(fn ($g) => $g->pluck('scope_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->all();
    }

    public static function leads(User $user, string $scopeType, int $id): bool
    {
        return in_array($id, self::scopes($user)[$scopeType] ?? [], true);
    }

    /** Allowed if the user has the broad permission, or leads this specific group. */
    public static function authorize(User $user, string $scopeType, int $id, string $permission): void
    {
        abort_unless($user->can($permission) || self::leads($user, $scopeType, $id), 403, 'This group is not one of yours.');
    }
}
