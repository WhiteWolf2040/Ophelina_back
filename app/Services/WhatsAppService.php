<?php

namespace App\Services;

use Twilio\Rest\Client;

class WhatsAppService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token')
        );
    }

    // Convierte 9991234567 o +529991234567 a +5219991234567
    public function normalizar(string $telefono): string
    {
        $digitos = preg_replace('/\D/', '', $telefono);
        return '+521' . substr($digitos, -10);
    }

    public function enviarTexto(string $telefono, string $mensaje): string
    {
        return $this->client->messages->create(
            'whatsapp:' . $this->normalizar($telefono),
            [
                'from' => config('services.twilio.from'),
                'body' => $mensaje,
            ]
        )->sid;
    }

    public function enviarPlantilla(string $telefono, string $contentSid, array $variables): string
    {
        return $this->client->messages->create(
            'whatsapp:' . $this->normalizar($telefono),
            [
                'from'             => config('services.twilio.from'),
                'contentSid'       => $contentSid,
                'contentVariables' => json_encode($variables),
            ]
        )->sid;
    }
}