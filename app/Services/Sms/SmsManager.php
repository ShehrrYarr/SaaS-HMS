<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal SMS gateway abstraction. Driver from HMS_SMS_DRIVER:
 *  - log    : writes messages to storage/logs (development)
 *  - twilio : TWILIO_SID / TWILIO_TOKEN / TWILIO_FROM
 *  - http   : generic GET gateway, SMS_HTTP_URL with {to} and {message} placeholders
 */
class SmsManager
{
    public function send(string $to, string $message): bool
    {
        return match (config('hms.sms_driver')) {
            'twilio' => $this->twilio($to, $message),
            'http' => $this->http($to, $message),
            default => $this->log($to, $message),
        };
    }

    protected function log(string $to, string $message): bool
    {
        Log::info("[SMS to {$to}] {$message}");

        return true;
    }

    protected function twilio(string $to, string $message): bool
    {
        $sid = env('TWILIO_SID');

        return Http::asForm()->withBasicAuth($sid, env('TWILIO_TOKEN'))
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $to, 'From' => env('TWILIO_FROM'), 'Body' => $message,
            ])->successful();
    }

    protected function http(string $to, string $message): bool
    {
        $url = str_replace(['{to}', '{message}'], [urlencode($to), urlencode($message)], (string) env('SMS_HTTP_URL'));

        return $url !== '' && Http::get($url)->successful();
    }
}
