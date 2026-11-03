<?php

namespace App\Support;

final class Phone
{
    /** "0772 123 456" / "772123456" / "+256772123456" → "+256772123456"; null when it can't be a mobile number. */
    public static function normalize(?string $raw, ?string $countryCode = null): ?string
    {
        if (blank($raw)) {
            return null;
        }
        $cc = $countryCode ?? config('services.sms.country_code', '256');
        $hasPlus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D/', '', $raw);

        if ($hasPlus || str_starts_with($digits, $cc)) {
            $e164 = '+'.$digits;
        } elseif (str_starts_with($digits, '0')) {
            $e164 = '+'.$cc.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $e164 = '+'.$cc.$digits;
        } else {
            return null;
        }

        return preg_match('/^\+\d{10,15}$/', $e164) ? $e164 : null;
    }
}
