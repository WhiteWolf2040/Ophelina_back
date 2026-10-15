<?php

namespace App\Mail;

use App\Models\Empresa;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RenovacionSuscripcion extends Mailable
{
    use SerializesModels;

    public function __construct(
        public Empresa $empresa,
        public string $planNombre,
        public float $monto,
        public string $moneda,
        public ?string $proximoCobro,
        public ?string $facturaUrl,
        public ?string $numeroFactura,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu suscripción a Ophelina se renovó');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.renovacion');
    }
}