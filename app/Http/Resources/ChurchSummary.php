<?php

namespace App\Http\Resources;

use App\Models\Church;

/** What the app needs to know about the signed-in user's church. */
class ChurchSummary
{
    public static function make(Church $c): array
    {
        return [
            'id' => (string) $c->id, 'name' => $c->name, 'slug' => $c->slug, 'status' => $c->status, 'plan' => $c->plan,
            'trialEndsAt' => $c->trial_ends_at?->toIso8601String(), 'trialDaysLeft' => $c->trialDaysLeft(),
            'onboarded' => $c->onboarded_at !== null,
        ];
    }
}
