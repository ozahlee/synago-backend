<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Default driver: writes messages to the log instead of sending. Safe for development and before credentials exist. */
class LogGateway implements SmsGateway
{
    public function send(array $numbers, string $message): array
    {
        Log::info('[sms:log] '.count($numbers).' recipient(s)', ['to' => $numbers, 'message' => $message]);

        return collect($numbers)->mapWithKeys(fn ($n) => [$n => ['ok' => true, 'id' => null, 'error' => null]])->all();
    }

    public function name(): string
    {
        return 'log';
    }
}
