<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;

class PrecioOroController extends Controller
{
    /**
     * Obtener el precio actual del oro (GLOBAL)
     */
    public function getPrecioActual(Request $request)
    {
        try {
            // Obtener el último precio registrado
            $precio = DB::table('precio_oro')
                ->orderBy('fecha_actualizacion', 'desc')
                ->first();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'precio_gramo' => $precio->precio_gramo_24k ?? 850,
                    'precio_onza' => $precio->precio_onza ?? ($precio->precio_gramo_24k * 31.1035),
                    'ultima_actualizacion' => $precio->fecha_actualizacion ?? null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener precio: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtener precios del oro por quilate (GLOBAL)
     */
   /**
 * Obtener precios del oro por quilate (GLOBAL)
 */
public function getPreciosQuilates(Request $request)
{
    try {
        // Obtener el último precio registrado
        $precio = DB::table('precio_oro')
            ->orderBy('fecha_actualizacion', 'desc')
            ->first();
        
        // Si hay datos en la BD, usarlos
        if ($precio && $precio->precio_gramo_24k > 0) {
            return response()->json([
                'success' => true,
                'data' => [
                    'precio_24k' => floatval($precio->precio_gramo_24k),
                    'precio_22k' => floatval($precio->precio_gramo_22k ?? round($precio->precio_gramo_24k * 0.9167, 2)),
                    'precio_21k' => floatval($precio->precio_gramo_21k ?? round($precio->precio_gramo_24k * 0.875, 2)),
                    'precio_18k' => floatval($precio->precio_gramo_18k ?? round($precio->precio_gramo_24k * 0.75, 2)),
                    'precio_14k' => floatval($precio->precio_gramo_14k ?? round($precio->precio_gramo_24k * 0.5833, 2)),
                    'precio_10k' => floatval($precio->precio_gramo_10k ?? round($precio->precio_gramo_24k * 0.4167, 2)),
                    'ultima_actualizacion' => $precio->fecha_actualizacion
                ]
            ]);
        }
        
        // Si no hay datos, intentar obtener desde la API
        $precios = $this->getPreciosDesdeGoldAPI();
        
        if ($precios) {
            // Guardar en BD
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
            
            return response()->json([
                'success' => true,
                'data' => [
                    'precio_24k' => $precios['24k'],
                    'precio_22k' => $precios['22k'],
                    'precio_21k' => $precios['21k'],
                    'precio_18k' => $precios['18k'],
                    'precio_14k' => $precios['14k'],
                    'precio_10k' => $precios['10k'],
                    'ultima_actualizacion' => now()
                ]
            ]);
        }
        
        // Último recurso: valores por defecto
        return response()->json([
            'success' => true,
            'data' => [
                'precio_24k' => 3170.83,
                'precio_22k' => 2906.59,
                'precio_21k' => 2774.47,
                'precio_18k' => 2378.12,
                'precio_14k' => 1849.65,
                'precio_10k' => 1321.18,
                'ultima_actualizacion' => null
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener precios: ' . $e->getMessage()
        ], 500);
    }
}
    /**
     * Actualizar precio del oro desde API externa (GLOBAL)
     */
    public function actualizarPrecio(Request $request)
    {
        try {
            // Obtener precio desde GoldAPI
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
                
                return response()->json([
                    'success' => true,
                    'message' => 'Precio del oro actualizado correctamente',
                    'data' => $precios
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'No se pudo obtener el precio del oro'
            ], 500);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar precio: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtener historial de precios del oro
     */
    public function historialPrecios(Request $request)
    {
        try {
            $historial = DB::table('precio_oro')
                ->orderBy('fecha_actualizacion', 'desc')
                ->limit(30)
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $historial
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener historial: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtener precios desde GoldAPI
     */
    private function getPreciosDesdeGoldAPI()
    {
        try {
            $apiKey = env('GOLD_API_KEY');
            
            if (!$apiKey) {
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
           
            return null;
        }
    }
}