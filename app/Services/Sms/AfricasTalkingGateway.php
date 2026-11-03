<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Throwable;

/** https://developers.africastalking.com/docs/sms/sending/bulk */
class AfricasTalkingGateway implements SmsGateway
{
    public function __construct(
        private string $username,
        private string $apiKey,
        private ?string $senderId = null,
        private bool $sandbox = false,
    ) {}

    public function send(array $numbers, string $message): array
    {
        $results = [];
        foreach (array_chunk($numbers, 100) as $chunk) {
            try {
                $res = Http::asForm()->acceptJson()->timeout(30)
                    ->withHeaders(['apiKey' => $this->apiKey])
                    ->post($this->baseUrl().'/version1/messaging', array_filter([
                        'username' => $this->username,
                        'to' => implode(',', $chunk),
                        'message' => $message,
                        'from' => $this->senderId,
                    ]));

                if ($res->failed()) {
                    foreach ($chunk as $n) {
                        $results[$n] = ['ok' => false, 'id' => null, 'error' => 'HTTP '.$res->status().': '.mb_substr($res->body(), 0, 150)];
                    }

                    continue;
                }

                $recipients = collect($res->json('SMSMessageData.Recipients', []))->keyBy('number');
                foreach ($chunk as $n) {
                    $r = $recipients->get($n);
                    $ok = $r && in_array((int) ($r['statusCode'] ?? 0), [100, 101, 102], true);
                    $results[$n] = ['ok' => $ok, 'id' => $r['messageId'] ?? null, 'error' => $ok ? null : ($r['status'] ?? 'Not accepted')];
                }
            } catch (Throwable $e) {
                foreach ($chunk as $n) {
                    $results[$n] = ['ok' => false, 'id' => null, 'error' => mb_substr($e->getMessage(), 0, 190)];
                }
            }
        }

        return $results;
    }

    public function name(): string
    {
        return 'africastalking';
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
    }
}
