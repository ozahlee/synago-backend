<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsBatch;
use App\Services\Sms\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bulk SMS to members, ministries, cell groups, birthdays or visitors. */
class SmsController extends Controller
{
    private const AUDIENCES = ['all', 'ministry', 'cell_group', 'gender', 'birthdays', 'visitors', 'members', 'numbers', 'staff', 'leaders'];

    public function __construct(private SmsService $sms) {}

    public function index(Request $request): JsonResponse
    {
        $rows = SmsBatch::with('sender')->inPeriod('created_at', ...$this->period($request))->latest()->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($rows->items())->map(fn (SmsBatch $b) => $this->present($b)),
            'meta' => $this->meta($rows),
            'driver' => $this->sms->driver(),
        ]);
    }

    public function show(SmsBatch $batch): JsonResponse
    {
        return response()->json(['data' => $this->present($batch->load('sender')) + [
            'messages' => $batch->messages()->orderBy('status')->orderBy('name')->get()
                ->map(fn ($m) => ['name' => $m->name, 'phone' => $m->phone, 'status' => $m->status, 'error' => $m->error]),
        ]]);
    }

    /** How many people an audience reaches, before sending. */
    public function preview(Request $request): JsonResponse
    {
        $audience = $this->audience($request);
        ['label' => $label, 'recipients' => $r, 'skipped' => $skipped] = $this->sms->resolve($audience);
        $message = (string) $request->input('message', '');

        return response()->json(['data' => [
            'label' => $label,
            'recipients' => $r->count(),
            'withoutPhone' => $skipped,
            'sample' => $r->take(5)->pluck('name')->filter()->values(),
            'example' => $message !== '' ? $this->sms->personalise($message, $r->first()['name'] ?? null) : null,
            'driver' => $this->sms->driver(),
        ]]);
    }

    public function send(Request $request): JsonResponse
    {
        $message = $request->validate(['message' => ['required', 'string', 'max:918']])['message']; // 6 SMS segments
        $audience = $this->audience($request);
        $batch = $this->sms->send($audience, $message, $request->user());

        abort_if($batch->recipient_count === 0, 422, 'Nobody in that audience has a usable phone number.');

        return response()->json(['data' => $this->present($batch->load('sender'))], 201);
    }

    private function audience(Request $request): array
    {
        return $request->validate([
            'audience.type' => ['required', Rule::in(self::AUDIENCES)],
            'audience.ministryId' => ['required_if:audience.type,ministry', 'integer', 'exists:ministries,id'],
            'audience.cellGroupId' => ['required_if:audience.type,cell_group', 'integer', 'exists:cell_groups,id'],
            'audience.gender' => ['required_if:audience.type,gender', Rule::in(['male', 'female'])],
            'audience.memberIds' => ['required_if:audience.type,members', 'array'],
            'audience.memberIds.*' => ['integer'],
            'audience.numbers' => ['required_if:audience.type,numbers', 'string', 'max:5000'],
        ])['audience'];
    }

    private function present(SmsBatch $b): array
    {
        return [
            'id' => (string) $b->id, 'message' => $b->message, 'audience' => $b->audience, 'audienceLabel' => $b->audience_label,
            'recipients' => $b->recipient_count, 'sent' => $b->sent_count, 'failed' => $b->failed_count,
            'driver' => $b->driver, 'sentBy' => $b->sender?->name, 'createdAt' => $b->created_at->toIso8601String(),
        ];
    }
}
