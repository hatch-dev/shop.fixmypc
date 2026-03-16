<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TwilioService
{
    protected $sid;
    protected $token;
    protected $from;

    public function __construct()
    {
        $this->sid = config('services.twilio.sid');
        $this->token = config('services.twilio.token');
        $this->from = config('services.twilio.from');
    }

    public function sendWhatsApp($to, $message)
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json";

        $response = Http::asForm()
            ->withBasicAuth($this->sid, $this->token)
            ->post($url, [
                'From' => 'whatsapp:' . $this->from,
                'To'   => 'whatsapp:' . $to,
                'Body' => $message,
            ]);

        return $response->json();
    }
}