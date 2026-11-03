<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Standing automatic reminders. Unknown keys fall back to DEFAULTS (all off until switched on). */
class ReminderRule extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'enabled', 'options'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'options' => 'array'];
    }

    public const DEFAULTS = [
        'service_eve' => [
            'label' => 'Sunday service reminder', 'when' => 'Saturday evening',
            'options' => ['time' => '18:00', 'audience' => ['type' => 'all'], 'channel' => 'sms',
                'message' => 'Dear {name}, you are warmly invited to worship with us tomorrow at {church}: {services}. God bless you!'],
        ],
        'birthdays' => [
            'label' => 'Birthday greetings', 'when' => 'Each morning, to members whose birthday it is (and a list to staff)',
            'options' => ['time' => '07:00', 'channel' => 'sms',
                'message' => 'Happy birthday {name}! The {church} family celebrates you today. May the Lord bless you and keep you. Numbers 6:24'],
        ],
        'group_meetings' => [
            'label' => 'Cell & ministry meeting reminders', 'when' => 'The evening before each weekly meeting, to that group',
            'options' => ['time' => '17:00', 'channel' => 'sms',
                'message' => 'Dear {name}, {group} meets tomorrow ({day}) at {time}{place}. See you there!'],
        ],
        'pledge_due' => [
            'label' => 'Pledge due reminders', 'when' => '7 days before a pledge is due, to the pledger',
            'options' => ['time' => '09:00', 'daysBefore' => 7, 'channel' => 'sms',
                'message' => 'Dear {name}, a gentle reminder that your pledge to {purpose} ({balance} remaining) is due on {date}. Thank you for your faithfulness.'],
        ],
        'preacher_rota' => [
            'label' => 'Preaching rota', 'when' => 'A few days before, to the member who is preaching',
            'options' => ['time' => '09:00', 'daysBefore' => 3, 'channel' => 'sms',
                'message' => 'Dear {name}, a reminder that you are preaching at the {service} on {date}{theme}{text}. God bless your preparation!'],
        ],
        'follow_ups' => [
            'label' => 'Pastoral follow-ups', 'when' => 'On the morning a follow-up visit is due, to the staff member who logged it',
            'options' => ['time' => '08:00', 'channel' => 'app'],
        ],
        'week_ahead' => [
            'label' => 'Week ahead for staff', 'when' => 'Monday morning: this week\'s events, burials and weddings',
            'options' => ['time' => '07:00', 'channel' => 'app', 'audience' => ['type' => 'staff']],
        ],
    ];

    public static function resolved(string $key): array
    {
        $row = static::find($key);
        $def = self::DEFAULTS[$key];

        return [
            'key' => $key, 'label' => $def['label'], 'when' => $def['when'],
            'enabled' => (bool) ($row?->enabled ?? false),
            'options' => array_merge($def['options'], $row?->options ?? []),
        ];
    }
}
