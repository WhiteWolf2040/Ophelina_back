<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Services\WhatsAppService;
use App\Models\Empeno;
use Carbon\Carbon;

class Kernel extends ConsoleKernel
{
    
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        $whatsapp = new WhatsAppService();
        
        $empenos = Empeno::where('estado', 'activo')
            ->whereBetween('fecha_vencimiento', [Carbon::now(), Carbon::now()->addDays(3)])
            ->with(['cliente', 'prenda'])
            ->get();
        
        foreach ($empenos as $empeno) {
            $dias = Carbon::now()->diffInDays($empeno->fecha_vencimiento, false);
            
            if ($dias >= 0 && $empeno->cliente->telefono) {
                $mensaje = "📢 OPHELINA - Recordatorio\n\n";
                $mensaje .= "Hola {$empeno->cliente->nombre},\n";
                $mensaje .= "Tu prenda '{$empeno->prenda->descripcion}' vence en {$dias} días.\n\n";
                $mensaje .= "Realiza tu pago para evitar cargos adicionales.";
                
                $whatsapp->sendMessage($empeno->cliente->telefono, $mensaje);
            }
        }
    })->dailyAt('09:00');
}

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }

    
}