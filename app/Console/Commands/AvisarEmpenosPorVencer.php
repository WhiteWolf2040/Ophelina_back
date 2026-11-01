<?php

namespace App\Console\Commands;

use App\Mail\EmpenoPorVencer;
use App\Models\Empeno;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AvisarEmpenosPorVencer extends Command
{
    protected $signature = 'empenos:avisar-vencimiento {--dias=3} {--empresa=}';
    protected $description = 'Envía correo a clientes cuyo empeño vence en N días o menos';

    public function handle(): int
    {
        $dias   = (int) $this->option('dias');
        $hoy    = now()->startOfDay();
        $limite = now()->addDays($dias)->endOfDay();

        // Modo prueba: si hay destinos, todo se manda a ellos (uno o varios)
        $prueba = array_filter((array) config('mail.destino_pruebas'));
        // Lista blanca: si tiene valores, solo se escribe a esos correos
        $allow  = array_map('strtolower', array_filter((array) config('mail.allowlist')));

        $empenos = Empeno::with(['cliente', 'empresa', 'prenda'])
            ->where('estado', 'activo')
            ->whereBetween('fecha_vencimiento', [$hoy, $limite])
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('id_empresa', $id))
            ->where(function ($q) {
                $q->whereNull('aviso_vencimiento_para')
                  ->orWhereColumn('aviso_vencimiento_para', '!=', 'fecha_vencimiento');
            })
            ->get();

        $enviados = 0;

        foreach ($empenos as $empeno) {
            $cliente = $empeno->cliente;

            if (!$cliente || !$cliente->correo) {
                $this->warn("Empeño {$empeno->folio}: cliente sin correo");
                continue;
            }

            if (empty($prueba) && !empty($allow) && !in_array(strtolower($cliente->correo), $allow)) {
                $this->line("Omitido {$empeno->folio}: {$cliente->correo} no está en la lista de prueba");
                continue;
            }

            $destino = !empty($prueba) ? $prueba : [$cliente->correo];

            try {
                $vence = Carbon::parse($empeno->fecha_vencimiento)->startOfDay();

                $mailable = new EmpenoPorVencer(
                    nombreCliente: trim($cliente->nombre . ' ' . $cliente->apellido),
                    nombreEmpresa: $empeno->empresa->nombre_comercial ?? $empeno->empresa->nombre,
                    descripcionPrenda: $empeno->prenda->descripcion ?? "Empeño {$empeno->folio}",
                    fechaVencimiento: $vence->locale('es')->translatedFormat('d \d\e F \d\e Y'),
                    diasRestantes: (int) $hoy->diffInDays($vence, false),
                    montoPendiente: (float) $empeno->monto_total,
                    telefonoEmpresa: $empeno->empresa->telefono,
                );

                if (config('services.mail_relay.url')) {
                    // Relé de Google Apps Script (HTTPS, sin dominio)
                    $respuesta = Http::timeout(30)->asJson()->post(config('services.mail_relay.url'), [
                        'secret'  => config('services.mail_relay.secret'),
                        'to'      => implode(',', $destino),
                        'subject' => $mailable->envelope()->subject,
                        'html'    => $mailable->render(),
                    ]);

                    if (!$respuesta->json('ok')) {
                        throw new \RuntimeException('El relé de correo respondió error');
                    }
                } else {
                    // Envío normal de Laravel (Resend, etc.)
                    Mail::to($destino)->send($mailable);
                }

                // En modo prueba no se marca, para poder repetir el envío
                if (empty($prueba)) {
                    $empeno->forceFill([
                        'aviso_vencimiento_para' => $vence->toDateString(),
                    ])->save();
                }

                $enviados++;
                $this->info("Enviado {$empeno->folio} -> " . implode(', ', $destino));
            } catch (\Throwable $e) {
                $this->error("Falló {$empeno->folio}: {$e->getMessage()}");
                Log::error("Aviso de vencimiento falló (empeño {$empeno->id_empeno}): " . $e->getMessage());
            }
        }

        $this->info("Avisos enviados: {$enviados}");
        Log::info("Avisos de vencimiento enviados: {$enviados}");

        return self::SUCCESS;
    }
}