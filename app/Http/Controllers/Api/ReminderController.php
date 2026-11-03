<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use App\Models\ReminderRule;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Services\Calendar\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Reminder queue, standing rules and personal reminders. People with sms.send manage church-wide
 * reminders; everyone can set personal reminders for themselves.
 */
class ReminderController extends Controller
{
    public function __construct(private ReminderService $reminders) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $user->can('sms.send');
        $status = $request->query('status', 'pending');
        $rows = Reminder::query()
            ->when(! $all, fn ($q) => $q->where('created_by', $user->id)->where('source', 'personal'))
            ->when($status === 'pending', fn ($q) => $q->where('status', 'pending')->orderBy('remind_at'),
                fn ($q) => $q->where('status', '!=', 'pending')->orderByDesc('remind_at'))
            ->paginate($this->perPage($request));

        $budget = (int) Setting::get('smsMonthlyBudget', 0);

        return response()->json([
            'data' => collect($rows->items())->map(fn ($r) => $this->present($r)),
            'meta' => $this->meta($rows),
            'stats' => [
                'smsUsedThisMonth' => SmsMessage::where('status', 'sent')->where('created_at', '>=', now()->startOfMonth())->count(),
                'smsBudget' => $budget ?: null,
                'pending' => Reminder::where('status', 'pending')->count(),
                'quietHours' => [Setting::get('quietHoursStart'), Setting::get('quietHoursEnd')],
                'lastRun' => cache('reminders:last_run:'.\App\Support\Tenant::id()),
            ],
        ]);
    }

    /** A one-off reminder: personal (to me, in-app) or, with sms.send, to any audience by SMS. */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'remindAt' => ['required', 'date'],
            'message' => ['required', 'string', 'max:918'],
            'channel' => ['sometimes', Rule::in(['sms', 'app', 'both'])],
            'audience' => ['nullable', 'array'],
            'audience.type' => ['required_with:audience', Rule::in(['all', 'staff', 'leaders', 'ministry', 'cell_group', 'members', 'users'])],
            'audience.ministryId' => ['nullable', 'integer'],
            'audience.cellGroupId' => ['nullable', 'integer'],
            'audience.memberIds' => ['nullable', 'array'],
            'audience.userIds' => ['nullable', 'array'],
        ]);
        $user = $request->user();
        $personal = empty($v['audience']);
        abort_if(! $personal && ! $user->can('sms.send'), 403, 'You can only set reminders for yourself.');

        $audience = $personal ? ['type' => 'users', 'userIds' => [$user->id]] : $v['audience'];
        $r = Reminder::create([
            'remind_at' => Carbon::parse($v['remindAt']), 'channel' => $personal ? 'app' : ($v['channel'] ?? 'sms'),
            'audience' => $audience, 'audience_label' => $personal ? 'Just me' : $this->reminders->label($audience),
            'message' => $v['message'], 'source' => $personal ? 'personal' : 'manual', 'created_by' => $user->id,
        ]);

        return response()->json(['data' => $this->present($r)], 201);
    }

    public function cancel(Request $request, Reminder $reminder): JsonResponse
    {
        abort_unless($request->user()->can('sms.send') || $reminder->created_by === $request->user()->id, 403);
        abort_unless($reminder->status === 'pending', 422, 'Only pending reminders can be cancelled.');
        $reminder->update(['status' => 'cancelled', 'note' => 'Cancelled by '.$request->user()->name]);

        return response()->json(['data' => $this->present($reminder)]);
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => collect(array_keys(ReminderRule::DEFAULTS))->map(fn ($k) => ReminderRule::resolved($k))->values()]);
    }

    public function updateRule(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(ReminderRule::DEFAULTS[$key]), 404);
        $v = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'options.time' => ['sometimes', 'date_format:H:i'],
            'options.message' => ['sometimes', 'string', 'max:459'],
            'options.channel' => ['sometimes', Rule::in(['sms', 'app', 'both'])],
            'options.daysBefore' => ['sometimes', 'integer', 'between:1,30'],
            'options.audience' => ['sometimes', 'array'],
            'options.audience.type' => ['required_with:options.audience', Rule::in(['all', 'staff', 'leaders', 'ministry', 'cell_group'])],
            'options.audience.ministryId' => ['nullable', 'integer'],
            'options.audience.cellGroupId' => ['nullable', 'integer'],
        ]);
        $row = ReminderRule::firstOrNew(['key' => $key]);
        if (array_key_exists('enabled', $v)) {
            $row->enabled = $v['enabled'];
        }
        if (isset($v['options'])) {
            $row->options = array_merge($row->options ?? [], $v['options']);
        }
        $row->save();

        return response()->json(['data' => ReminderRule::resolved($key)]);
    }

    /** Run the scheduler step now (useful right after switching rules on, or if cron is not set up yet). */
    public function runNow(): JsonResponse
    {
        $result = $this->reminders->run();
        cache()->forever('reminders:last_run:'.\App\Support\Tenant::id(), now(config('church.timezone'))->toIso8601String());

        return response()->json(['data' => $result]);
    }

    private function present(Reminder $r): array
    {
        return [
            'id' => (string) $r->id, 'remindAt' => $r->remind_at->format('Y-m-d\TH:i'), 'channel' => $r->channel,
            'audience' => $r->audience, 'audienceLabel' => $r->audience_label, 'message' => $r->message, 'status' => $r->status,
            'source' => $r->source, 'sentCount' => $r->sent_count, 'note' => $r->note,
        ];
    }
}
