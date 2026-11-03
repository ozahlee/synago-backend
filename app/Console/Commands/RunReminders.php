<?php

namespace App\Console\Commands;

use App\Models\Church;
use App\Services\Calendar\ReminderService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class RunReminders extends Command
{
    protected $signature = 'reminders:run';

    protected $description = 'Schedule automatic church reminders and send the ones that are due';

    public function handle(ReminderService $reminders): int
    {
        // every church that isn't suspended, each in its own context
        foreach (Church::where('status', '!=', 'suspended')->orderBy('id')->get() as $church) {
            $r = Tenant::run($church->id, fn () => $reminders->run());
            cache()->forever("reminders:last_run:{$church->id}", now(config('church.timezone'))->toIso8601String());
            $this->info("{$church->name}: created {$r['created']}, sent {$r['sent']}, postponed {$r['postponed']}, skipped {$r['skipped']}");
        }

        return self::SUCCESS;
    }
}
