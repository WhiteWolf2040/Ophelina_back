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
    protected $signature = 'empenos:avisar-vencimiento {--dias=3}';
    protected $description = 'Envía correo a clientes cuyo empeño vence en N días o menos';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');
        $hoy = now()->startOfDay();
        $limite = now()->addDays($dias)->endOfDay();

        $empenos = Empeno::with(['cliente', 'empresa'])   // ajusta relaciones
            ->where('estado', 'activo')                    // ajusta a tu estado de "vigente"
            ->whereBetween('fecha_vencimiento', [$hoy, $limite])
            ->where(function ($q) {
                $q->whereNull('aviso_vencimiento_para')
                  ->orWhereColumn('aviso_vencimiento_para', '!=', 'fecha_vencimiento');
            })
            ->get();

        $enviados = 0;

        foreach ($empenos as $empeno) {
            $cliente = $empeno->cliente;

            if (!$cliente || !$cliente->email) {
                continue;
            }

            try {
                $vence = Carbon::parse($empeno->fecha_vencimiento)->startOfDay();

               Mail::to(config('mail.destino_pruebas') ?: $cliente->email)->send(new EmpenoPorVencer(
                    nombreCliente: $cliente->nombre,
                    nombreEmpresa: $empeno->empresa->nombre_comercial ?? $empeno->empresa->nombre,
                    descripcionPrenda: $empeno->descripcion ?? 'Prenda empeñada', // ajusta
                    fechaVencimiento: $vence->locale('es')->translatedFormat('d \d\e F \d\e Y'),
                    diasRestantes: (int) $hoy->diffInDays($vence, false),
                    montoPendiente: (float) ($empeno->monto_prestamo ?? 0),       // ajusta
                    telefonoEmpresa: $empeno->empresa->telefono,
                ));

                // Marca la fecha avisada para no repetir el correo
                $empeno->update(['aviso_vencimiento_para' => $vence->toDateString()]);
                $enviados++;
            } catch (\Throwable $e) {
                Log::error("❌ Aviso de vencimiento falló (empeño {$empeno->id_empeno}): " . $e->getMessage());
            }
        }

        $this->info("Avisos enviados: {$enviados}");
        Log::info("📧 Avisos de vencimiento enviados: {$enviados}");

        return self::SUCCESS;
    }
}