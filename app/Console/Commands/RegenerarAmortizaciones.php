<?php
// app/Console/Commands/RegenerarAmortizaciones.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Empeno;
use App\Models\Amortizacio;

class RegenerarAmortizaciones extends Command
{
    protected $signature = 'amortizaciones:regenerar';
    protected $description = 'Regenera todas las amortizaciones con cálculos correctos';

    public function handle()
    {
        $empenos = Empeno::all();
        
        foreach ($empenos as $empeno) {
            // Eliminar amortizaciones existentes
            Amortizacio::where('id_empeno', $empeno->id_empeno)->delete();
            
            // Calcular valores correctos
            $capital = $empeno->monto_prestado;
            $tasa = $empeno->intereses ?? 10; // 10% por defecto
            $interes = $capital * ($tasa / 100);
            $ivaInteres = $interes * 0.16;
            $montoTotal = $capital + $interes + $ivaInteres;
            
            // Crear amortización correcta
            Amortizacio::create([
                'id_empeno' => $empeno->id_empeno,
                'saldo_inicial' => $montoTotal,
                'saldo_final' => $montoTotal,
                'numero_pago' => 1,
                'fecha_pago_programado' => $empeno->fecha_vencimiento,
                'capital' => $capital,
                'interes' => $interes,
                'iva_interes' => $ivaInteres,
                'monto_total' => $montoTotal,
                'monto_pagado' => 0,
                'estado' => 'pendiente'
            ]);
            
            $this->info("Empeño ID {$empeno->id_empeno}: Capital ${$capital}, Interés ${$interes}, IVA ${$ivaInteres}, Total ${$montoTotal}");
        }
        
        $this->info("✅ Todas las amortizaciones regeneradas correctamente");
    }
}