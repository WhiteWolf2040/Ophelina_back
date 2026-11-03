<?php

namespace App\Console\Commands;

use App\Models\Empeno;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnviarAvisosEmpeno extends Command
{
    protected $signature = 'empenos:avisar-whatsapp {--dias=3} {--empresa=}';
    protected $description = 'Envía WhatsApp a clientes cuyo empeño vence en N días o menos';

    public function handle(WhatsAppService $wa): int
    {
        $dias   = (int) $this->option('dias');
        $hoy    = now()->startOfDay();
        $limite = now()->addDays($dias)->endOfDay();

        $prueba = array_filter(array_map('trim', explode(',', (string) config('services.twilio.destino_pruebas'))));

        $empenos = Empeno::with(['cliente', 'empresa', 'prenda'])
            ->where('estado', 'activo')
            ->whereBetween('fecha_vencimiento', [$hoy, $limite])
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('id_empresa', $id))
            ->where(function ($q) {
                $q->whereNull('aviso_whatsapp_para')
                  ->orWhereColumn('aviso_whatsapp_para', '!=', 'fecha_vencimiento');
            })
            ->get();

        $enviados = 0;

        foreach ($empenos as $empeno) {
            $cliente = $empeno->cliente;

            if (!$cliente || !$cliente->telefono || !$cliente->acepta_whatsapp) {
                $this->warn("Empeño {$empeno->folio}: cliente sin teléfono o sin consentimiento");
                continue;
            }

            $destinos = !empty($prueba) ? $prueba : [$cliente->telefono];

            try {
                $vence    = Carbon::parse($empeno->fecha_vencimiento)->startOfDay();
                $restan   = (int) $hoy->diffInDays($vence, false);
                $nombre   = trim($cliente->nombre . ' ' . $cliente->apellido);
                $empresa  = $empeno->empresa->nombre_comercial ?? $empeno->empresa->nombre;
                $prenda   = $empeno->prenda->descripcion ?? "Empeño {$empeno->folio}";
                $fecha    = $vence->locale('es')->translatedFormat('d \d\e F \d\e Y');
                $cuando   = $restan <= 0 ? 'vence HOY' : "vence en {$restan} día(s)";
                $monto    = number_format((float) $empeno->monto_total, 2);
                $link     = config('services.twilio.url_app');

                foreach ($destinos as $telefono) {
                    if (config('services.twilio.modo') === 'plantilla') {
                        // PRODUCCIÓN: plantilla aprobada con 5 variables
                        $wa->enviarPlantilla($telefono, config('services.twilio.content_sid'), [
                            '1' => $nombre,
                            '2' => $prenda,
                            '3' => "{$cuando} ({$fecha})",
                            '4' => '$' . $monto,
                            '5' => $link,
                        ]);
                    } else {
                        // SANDBOX: texto libre
                        $wa->enviarTexto($telefono,
                        "Hola {$nombre} 👋\n\n"
                        . "Te recordamos que tu empeño de \"{$prenda}\" en {$empresa} {$cuando} ({$fecha}).\n"
                        . "💰 Monto pendiente: \${$monto}\n\n"
                        . "Consulta o refrenda aquí:\n{$link}\n\n"
                        . ($empeno->empresa->telefono ? "📞 {$empeno->empresa->telefono}" : '')
                    );
                                        }
                }

                if (empty($prueba)) {
                    $empeno->forceFill(['aviso_whatsapp_para' => $vence->toDateString()])->save();
                }

                $enviados++;
                $this->info("Enviado {$empeno->folio} -> " . implode(', ', $destinos));
            } catch (\Throwable $e) {
                $this->error("Falló {$empeno->folio}: {$e->getMessage()}");
                Log::error("Aviso WhatsApp falló (empeño {$empeno->id_empeno}): " . $e->getMessage());
            }
        }

        $this->info("Avisos WhatsApp enviados: {$enviados}");
        Log::info("Avisos WhatsApp enviados: {$enviados}");

        return self::SUCCESS;
    }
}