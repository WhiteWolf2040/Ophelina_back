<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    protected $apiKey;
    protected $defaultPhone;

    public function __construct()
    {
        $this->apiKey = config('services.callmebot.apikey');
        $this->defaultPhone = config('services.callmebot.phone');
    }

    public function sendMessage($phone, $message)
    {
        // Limpiar número: eliminar cualquier carácter no numérico
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Asegurar formato internacional (código país 52 para México)
        if (strlen($phone) === 10) {
            $phone = '52' . $phone;
        }

        $response = Http::get('https://api.callmebot.com/whatsapp.php', [
            'phone' => $phone,
            'text' => $message,
            'apikey' => $this->apiKey
        ]);

        return $response->successful();
    }
}