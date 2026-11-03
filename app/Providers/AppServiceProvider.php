<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch lazy-loading N+1s, silently-dropped mass assignment, etc. outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Date columns are stored as "Y-m-d 00:00:00", so a plain whereBetween with "Y-m-d" strings misses the last day.
        // whereDateBetween compares on the date part only, inclusive at both ends.
        \Illuminate\Database\Eloquent\Builder::macro('whereDateBetween', function (string $column, array $range) {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            return $this->whereDate($column, '>=', (string) $range[0])->whereDate($column, '<=', (string) $range[1]);
        });
        \Illuminate\Database\Query\Builder::macro('whereDateBetween', function (string $column, array $range) {
            /** @var \Illuminate\Database\Query\Builder $this */
            return $this->whereDate($column, '>=', (string) $range[0])->whereDate($column, '<=', (string) $range[1]);
        });

        // Optional period filter: either end may be null (open-ended).
        \Illuminate\Database\Eloquent\Builder::macro('inPeriod', function (string $column, ?string $from, ?string $to) {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            return $this->when($from, fn ($q) => $q->whereDate($column, '>=', $from))->when($to, fn ($q) => $q->whereDate($column, '<=', $to));
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // exists:/unique: rules only look inside the current church
        $this->app->extend('validation.presence', fn ($verifier, $app) => new \App\Support\TenantPresenceVerifier($app['db']));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
