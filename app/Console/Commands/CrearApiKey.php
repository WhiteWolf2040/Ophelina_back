<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrearApiKey extends Command
{
    protected $signature = 'apikey:crear {id_empresa} {nombre}';
    protected $description = 'Genera una API key para el POS ligada a una empresa';

    public function handle(): int
    {
        $llave = 'oph_' . Str::random(40);

        ApiKey::create([
            'id_empresa' => $this->argument('id_empresa'),
            'nombre'     => $this->argument('nombre'),
            'key_hash'   => hash('sha256', $llave),
        ]);

        $this->info('Guarda esta llave ahora, no se vuelve a mostrar:');
        $this->line($llave);

        return self::SUCCESS;
    }
}