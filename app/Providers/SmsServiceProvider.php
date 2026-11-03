<?php

namespace App\Providers;

use App\Services\Sms\AfricasTalkingGateway;
use App\Services\Sms\LogGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, function () {
            $cfg = config('services.sms');
            if ($cfg['driver'] === 'africastalking' && filled($cfg['africastalking']['api_key'])) {
                $at = $cfg['africastalking'];

                return new AfricasTalkingGateway($at['username'], $at['api_key'], $at['sender_id'], (bool) $at['sandbox']);
            }

            return new LogGateway;
        });
    }
}
