<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;

class UpdateGoldPrice extends Command
{
    protected $signature = 'gold:update-price';
    protected $description = 'Actualiza el precio del oro desde GoldAPI.io';

    public function handle()
    {
        $this->info('🔄 Iniciando actualización del precio del oro...');
        
        $precios = $this->getPreciosDesdeGoldAPI();
        
        if ($precios) {
            // Insertar nuevo precio en la tabla global
            DB::table('precio_oro')->insert([
                'precio_gramo_24k' => $precios['24k'],
                'precio_gramo_22k' => $precios['22k'],
                'precio_gramo_21k' => $precios['21k'],
                'precio_gramo_18k' => $precios['18k'],
                'precio_gramo_14k' => $precios['14k'],
                'precio_gramo_10k' => $precios['10k'],
                'precio_onza' => $precios['onza'],
                'moneda' => 'MXN',
                'fuente' => 'GoldAPI.io',
                'fecha_actualizacion' => now()
            ]);
            
            $this->info("✅ Precio actualizado: $" . number_format($precios['24k'], 2) . " MXN/gramo (24k)");
            $this->info("   Oro 22k: $" . number_format($precios['22k'], 2) . " /gramo");
            $this->info("   Oro 18k: $" . number_format($precios['18k'], 2) . " /gramo");
        } else {
            $this->error('❌ No se pudo obtener el precio del oro');
        }
    }
    
    private function getPreciosDesdeGoldAPI()
    {
        try {
            $apiKey = env('GOLD_API_KEY');
            
            if (!$apiKey) {
                $this->error('⚠️  No has configurado GOLD_API_KEY en .env');
                return null;
            }
            
            $client = new Client(['timeout' => 10]);
            $response = $client->get("https://www.goldapi.io/api/XAU/MXN", [
                'headers' => [
                    'x-access-token' => $apiKey,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            if (isset($data['price_gram_24k'])) {
                return [
                    '24k' => $data['price_gram_24k'],
                    '22k' => $data['price_gram_22k'] ?? round($data['price_gram_24k'] * 0.9167, 2),
                    '21k' => $data['price_gram_21k'] ?? round($data['price_gram_24k'] * 0.875, 2),
                    '18k' => $data['price_gram_18k'] ?? round($data['price_gram_24k'] * 0.75, 2),
                    '14k' => $data['price_gram_14k'] ?? round($data['price_gram_24k'] * 0.5833, 2),
                    '10k' => $data['price_gram_10k'] ?? round($data['price_gram_24k'] * 0.4167, 2),
                    'onza' => $data['price'] ?? $data['price_gram_24k'] * 31.1035
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            $this->error('Error GoldAPI: ' . $e->getMessage());
            return null;
        }
    }
}