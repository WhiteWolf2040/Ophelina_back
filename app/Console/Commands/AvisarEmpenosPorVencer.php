<?php

namespace App\Console\Commands;

use App\Mail\EmpenoPorVencer;
use App\Models\Empeno;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AvisarEmpenosPorVencer extends Command
{
    protected $signature = 'empenos:avisar-vencimiento {--dias=3} {--empresa=}';
    protected $description = 'Envía correo a clientes cuyo empeño vence en N días o menos';

    public function handle(): int
    {
        $dias    = (int) $this->option('dias');
        $hoy     = now()->startOfDay();
        $limite  = now()->addDays($dias)->endOfDay();
        $prueba  = config('mail.destino_pruebas');

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

            try {
                $vence = Carbon::parse($empeno->fecha_vencimiento)->startOfDay();

                Mail::to($prueba ?: $cliente->correo)->send(new EmpenoPorVencer(
                    nombreCliente: trim($cliente->nombre . ' ' . $cliente->apellido),
                    nombreEmpresa: $empeno->empresa->nombre_comercial ?? $empeno->empresa->nombre,
                    descripcionPrenda: $empeno->prenda->descripcion ?? "Empeño {$empeno->folio}",
                    fechaVencimiento: $vence->locale('es')->translatedFormat('d \d\e F \d\e Y'),
                    diasRestantes: (int) $hoy->diffInDays($vence, false),
                    montoPendiente: (float) $empeno->monto_total,
                    telefonoEmpresa: $empeno->empresa->telefono,
                ));

                // En modo prueba no se marca, para poder repetir el envío
                if (!$prueba) {
                    $empeno->forceFill([
                        'aviso_vencimiento_para' => $vence->toDateString(),
                    ])->save();
                }

                $enviados++;
                $this->info("Enviado {$empeno->folio} -> " . ($prueba ?: $cliente->correo));
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