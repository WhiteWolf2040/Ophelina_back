<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Empeno;

class ActualizarEstadosEmpenos extends Command
{
    protected $signature = 'empenos:actualizar-estados';
    protected $description = 'Actualiza empeños activos vencidos a estado vencido';

    public function handle()
    {
        $actualizados = Empeno::where('estado', 'activo')
            ->where('fecha_vencimiento', '<', now())
            ->update(['estado' => 'vencido']);

        $this->info("Se actualizaron {$actualizados} empeños a vencidos.");
    }
}