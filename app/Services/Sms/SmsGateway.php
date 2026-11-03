<?php

namespace App\Services\Sms;

interface SmsGateway
{
    /**
     * Send one message to many numbers (E.164).
     *
     * @param  list<string>  $numbers
     * @return array<string, array{ok: bool, id: ?string, error: ?string}> keyed by number
     */
    public function send(array $numbers, string $message): array;

    public function name(): string;
}
