<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmpenoPorVencer extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $nombreCliente,
        public string $nombreEmpresa,
        public string $descripcionPrenda,
        public string $fechaVencimiento,
        public int $diasRestantes,
        public float $montoPendiente,
        public ?string $telefonoEmpresa = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tu empeño en {$this->nombreEmpresa} está por vencer"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.empeno_por_vencer');
    }
}