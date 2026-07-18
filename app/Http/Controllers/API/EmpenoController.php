<?php
// app/Http/Controllers/Api/EmpenoController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empeno;
use App\Models\Prenda;
use App\Models\Pago;
use App\Models\ProductoTienda;
use App\Models\Cliente;
use App\Models\TasaInteres;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EmpenoController extends Controller
{
    protected $whatsapp;

    public function __construct(WhatsAppService $whatsapp)
    {
        $this->whatsapp = $whatsapp;
        $this->middleware('auth:api');
    }

    /**
     * Listar empeños con filtros
     * GET /api/empenos
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $empresaId = $user->id_empresa;

            $query = Empeno::with(['cliente', 'prenda', 'pagos', 'tasa'])
                ->where('id_empresa', $empresaId);

            // Filtros
            if ($request->has('estado') && $request->estado) {
                $query->where('estado', $request->estado);
            }

            if ($request->has('cliente_id') && $request->cliente_id) {
                $query->where('id_cliente', $request->cliente_id);
            }

            if ($request->has('fecha_desde') && $request->fecha_desde) {
                $query->whereDate('fecha_empeno', '>=', $request->fecha_desde);
            }

            if ($request->has('fecha_hasta') && $request->fecha_hasta) {
                $query->whereDate('fecha_empeno', '<=', $request->fecha_hasta);
            }

            if ($request->has('busqueda') && $request->busqueda) {
                $busqueda = $request->busqueda;
                $query->where(function($q) use ($busqueda) {
                    $q->where('folio', 'LIKE', "%{$busqueda}%")
                      ->orWhereHas('cliente', function($cq) use ($busqueda) {
                          $cq->where('nombre', 'LIKE', "%{$busqueda}%")
                             ->orWhere('apellido', 'LIKE', "%{$busqueda}%");
                      });
                });
            }

            $empenos = $query->orderBy('fecha_empeno', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $empenos
            ]);

        } catch (\Exception $e) {
            Log::error('Error en index empeños: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener detalle de un empeño
     * GET /api/empenos/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $empeno = Empeno::where('id_empresa', $user->id_empresa)
                ->where('id_empeno', $id)
                ->with(['cliente', 'prenda', 'amortizaciones', 'pagos', 'tasa'])
                ->first();
            
            if (!$empeno) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empeño no encontrado'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $empeno
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en show empeño: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener empeños activos con saldo pendiente
     * GET /api/empenos/activos-con-saldo
     */
    public function activosConSaldo(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }
            
            $empenos = Empeno::where('estado', 'activo')
                ->where('id_empresa', $user->id_empresa)
                ->with(['cliente', 'prenda'])
                ->get();
            
            $resultados = [];
            
            foreach ($empenos as $empeno) {
                $totalPagado = DB::table('pagos')
                    ->where('id_empeno', $empeno->id_empeno)
                    ->sum('monto') ?? 0;
                
                $amortizacionPendiente = DB::table('amortizacion')
                    ->where('id_empeno', $empeno->id_empeno)
                    ->where('estado', 'pendiente')
                    ->orderBy('numero_pago', 'asc')
                    ->first();
                
                $saldoPendienteCuota = 0;
                if ($amortizacionPendiente) {
                    $saldoPendienteCuota = ($amortizacionPendiente->monto_total ?? 0) - ($amortizacionPendiente->monto_pagado ?? 0);
                }
                
                $saldoTotalPendiente = max(0, ($empeno->monto_prestado ?? 0) - $totalPagado);
                
                $resultados[] = [
                    'id_empeno' => $empeno->id_empeno,
                    'cliente' => $empeno->cliente ? $empeno->cliente->nombre . ' ' . $empeno->cliente->apellido : 'Cliente no disponible',
                    'articulo' => $empeno->prenda ? $empeno->prenda->descripcion : 'Sin artículo',
                    'monto_prestado' => floatval($empeno->monto_prestado ?? 0),
                    'total_pagado' => floatval($totalPagado),
                    'saldo_total_pendiente' => floatval($saldoTotalPendiente),
                    'saldo_pendiente_cuota' => floatval($saldoPendienteCuota),
                    'fecha_empeno' => $empeno->fecha_empeno,
                    'fecha_vencimiento' => $empeno->fecha_vencimiento
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $resultados
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en activosConSaldo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empeños: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo empeño
     * POST /api/empenos
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            $validated = $request->validate([
                'id_cliente' => 'required|exists:clientes,id_cliente',
                'id_prenda' => 'required|exists:prendas,id_prenda',
                'id_tasa' => 'required|exists:tasas_interes,id_tasa',
                'monto_prestado' => 'required|numeric|min:100',
                'fecha_vencimiento' => 'required|date|after:today',
                'id_aval' => 'nullable|exists:aval,id_aval',
                'dias_gracia' => 'integer|min:0|max:30',
                'notas' => 'nullable|string'
            ]);
            
            // Obtener la tasa de interés
            $tasa = TasaInteres::find($validated['id_tasa']);
            if (!$tasa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tasa de interés no encontrada'
                ], 404);
            }
            
            // Calcular intereses
            $fechaInicio = now();
            $fechaVencimiento = Carbon::parse($validated['fecha_vencimiento']);
            $dias = $fechaInicio->diffInDays($fechaVencimiento);
            
            $interesMonto = $validated['monto_prestado'] * ($tasa->porcentaje / 100) * ($dias / 30);
            $ivaInteres = $interesMonto * 0.16;
            $montoTotal = $validated['monto_prestado'] + $interesMonto + $ivaInteres;
            
            // Generar folio único
            $folio = 'EMP-' . strtoupper(uniqid());
            
            DB::beginTransaction();
            
            // 1. Crear el empeño
            $empeno = Empeno::create([
                'id_empresa' => $user->id_empresa,
                'id_cliente' => $validated['id_cliente'],
                'id_prenda' => $validated['id_prenda'],
                'id_aval' => $validated['id_aval'] ?? null,
                'id_tasa' => $validated['id_tasa'],
                'folio' => $folio,
                'fecha_empeno' => now(),
                'fecha_vencimiento' => $validated['fecha_vencimiento'],
                'monto_prestado' => $validated['monto_prestado'],
                'intereses' => $tasa->porcentaje,
                'iva_porcentaje' => 16.00,
                'monto_total' => $montoTotal,
                'estado' => 'activo',
                'dias_gracia' => $validated['dias_gracia'] ?? 5,
                'notas' => $validated['notas'] ?? null
            ]);
            
            // 2. Actualizar estado de la prenda
            Prenda::where('id_prenda', $validated['id_prenda'])
                ->update(['estado' => 'En Empeño']);
            
            // 3. Crear amortización inicial
            DB::table('amortizacion')->insert([
                'id_empeno' => $empeno->id_empeno,
                'saldo_inicial' => $montoTotal,
                'saldo_final' => $montoTotal,
                'numero_pago' => 1,
                'fecha_pago_programado' => $validated['fecha_vencimiento'],
                'capital' => $validated['monto_prestado'],
                'interes' => $interesMonto,
                'iva_interes' => $ivaInteres,
                'monto_total' => $montoTotal,
                'monto_pagado' => 0,
                'estado' => 'pendiente'
            ]);
            
            // 4. Registrar movimiento de caja
            DB::table('movimientos_caja')->insert([
                'tipo' => 'prestamo',
                'monto' => $validated['monto_prestado'],
                'descripcion' => 'Préstamo por empeño - Folio: ' . $folio,
                'id_usuario' => $user->id_usuario,
                'fecha' => now(),
                'id_empresa' => $user->id_empresa
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Empeño registrado correctamente',
                'data' => $empeno->load(['cliente', 'prenda', 'tasa'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al registrar empeño: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar empeño: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recuperar empeño (pago completo)
     * POST /api/empenos/{id}/recuperar
     */
    public function recuperar(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $empeno = Empeno::where('id_empeno', $id)
                ->where('id_empresa', $user->id_empresa)
                ->where('estado', 'activo')
                ->firstOrFail();

            $request->validate([
                'monto_pagado' => 'required|numeric|min:0',
                'id_metodo_pago' => 'required|exists:metodo_pago,id_metodo',
                'referencia' => 'nullable|string'
            ]);

            DB::beginTransaction();

            // Marcar como recuperado
            $empeno->estado = 'recuperado';
            $empeno->fecha_recuperacion = now();
            $empeno->save();

            // Registrar pago
            $pago = Pago::create([
                'id_empeno' => $empeno->id_empeno,
                'monto' => $request->monto_pagado,
                'fecha_pago' => now(),
                'tipo_pago' => 'recuperacion',
                'id_metodo_pago' => $request->id_metodo_pago,
                'referencia' => $request->referencia
            ]);

            // Actualizar estado de la prenda
            Prenda::where('id_prenda', $empeno->id_prenda)
                ->update(['estado' => 'Disponible']);

            // Ocultar producto de tienda si existe
            ProductoTienda::where('id_prenda', $empeno->id_prenda)
                ->where('id_empresa', $user->id_empresa)
                ->update(['visible' => false]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Empeño recuperado correctamente',
                'data' => [
                    'empeno' => $empeno,
                    'pago' => $pago
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al recuperar empeño: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al recuperar empeño: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Renovar empeño (pagar solo intereses)
     * POST /api/empenos/{id}/renovar
     */
    public function renovar(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $empeno = Empeno::where('id_empeno', $id)
                ->where('id_empresa', $user->id_empresa)
                ->where('estado', 'activo')
                ->firstOrFail();

            $request->validate([
                'monto_pagado' => 'required|numeric|min:0',
                'id_metodo_pago' => 'required|exists:metodo_pago,id_metodo',
                'dias_extension' => 'integer|min:15|max:90',
                'referencia' => 'nullable|string'
            ]);

            $diasExtension = $request->dias_extension ?? 30;
            $nuevaFechaVencimiento = Carbon::parse($empeno->fecha_vencimiento)->addDays($diasExtension);

            DB::beginTransaction();

            // Registrar pago de intereses
            $pago = Pago::create([
                'id_empeno' => $empeno->id_empeno,
                'monto' => $request->monto_pagado,
                'fecha_pago' => now(),
                'tipo_pago' => 'intereses',
                'id_metodo_pago' => $request->id_metodo_pago,
                'referencia' => $request->referencia
            ]);

            // Actualizar fecha de vencimiento
            $empeno->fecha_vencimiento = $nuevaFechaVencimiento;
            $empeno->save();

            // Actualizar amortización
            DB::table('amortizacion')
                ->where('id_empeno', $empeno->id_empeno)
                ->where('estado', 'pendiente')
                ->update([
                    'fecha_pago_programado' => $nuevaFechaVencimiento,
                    'monto_pagado' => DB::raw('monto_pagado + ' . $request->monto_pagado)
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Empeño renovado correctamente',
                'data' => [
                    'empeno' => $empeno,
                    'pago' => $pago,
                    'nueva_fecha_vencimiento' => $nuevaFechaVencimiento
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al renovar empeño: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al renovar empeño: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publicación automática de empeños vencidos
     * POST /api/empenos/publicar-vencidos
     */
    public function publicarVencidos(Request $request)
    {
        try {
            $user = $request->user();
            $empresaId = $user->id_empresa;
            
            // Obtener días de gracia de la empresa (o usar default)
            $diasGracia = $request->dias_gracia ?? 5;

            $empenosVencidos = Empeno::where('id_empresa', $empresaId)
                ->where('estado', 'activo')
                ->where('fecha_vencimiento', '<=', now()->subDays($diasGracia))
                ->with(['prenda'])
                ->get();

            $creados = [];
            $errores = [];

            foreach ($empenosVencidos as $empeno) {
                // Verificar si ya existe producto
                $existe = ProductoTienda::where('id_prenda', $empeno->id_prenda)
                    ->where('id_empresa', $empresaId)
                    ->exists();

                if (!$existe && $empeno->prenda) {
                    try {
                        // Calcular precio sugerido (70% del valor estimado)
                        $precioSugerido = ($empeno->prenda->valor_estimado ?? 0) * 0.7;

                        $producto = ProductoTienda::create([
                            'id_prenda' => $empeno->id_prenda,
                            'id_empresa' => $empresaId,
                            'nombre' => $empeno->prenda->descripcion ?? 'Prenda sin descripción',
                            'descripcion' => $empeno->prenda->descripcion ?? '',
                            'precio' => max(100, $precioSugerido),
                            'descuento' => 0,
                            'stock' => 1,
                            'categoria' => $empeno->prenda->tipo ?? 'Otros',
                            'estado' => 'Buen estado',
                            'visible' => true,
                            'destacado' => false,
                            'fecha_publicacion' => now(),
                            'publicacion_automatica' => true,
                            'fecha_vencimiento_contrato' => $empeno->fecha_vencimiento,
                            'dias_gracia' => $diasGracia
                        ]);

                        $creados[] = $producto;
                        
                    } catch (\Exception $e) {
                        $errores[] = [
                            'empeno_id' => $empeno->id_empeno,
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Publicación automática completada',
                'data' => [
                    'productos_creados' => count($creados),
                    'productos' => $creados,
                    'errores' => $errores
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error en publicación automática: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error en publicación automática: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enviar recordatorios de vencimiento por WhatsApp
     * POST /api/empenos/enviar-recordatorios
     */
    public function enviarRecordatorios(Request $request)
    {
        try {
            $user = $request->user();
            $empresaId = $user->id_empresa;
            
            $diasAntes = $request->dias_antes ?? 3;
            
            $empenos = Empeno::where('id_empresa', $empresaId)
                ->where('estado', 'activo')
                ->whereBetween('fecha_vencimiento', [
                    now(),
                    now()->addDays($diasAntes)
                ])
                ->with(['cliente', 'prenda'])
                ->get();
            
            $enviados = 0;
            $errores = [];

            foreach ($empenos as $empeno) {
                if (!$empeno->cliente || !$empeno->cliente->telefono) {
                    continue;
                }

                $diasRestantes = now()->diffInDays($empeno->fecha_vencimiento, false);
                
                if ($diasRestantes >= 0 && $diasRestantes <= $diasAntes) {
                    $mensaje = "📢 OPHELINA - Recordatorio de Vencimiento\n\n";
                    $mensaje .= "Hola {$empeno->cliente->nombre},\n";
                    $mensaje .= "Tu prenda '{$empeno->prenda->descripcion}' vence en {$diasRestantes} días.\n\n";
                    $mensaje .= "📅 Fecha de vencimiento: " . $empeno->fecha_vencimiento->format('d/m/Y') . "\n";
                    $mensaje .= "💰 Monto total: $" . number_format($empeno->monto_total, 2) . "\n\n";
                    $mensaje .= "Realiza tu pago para evitar cargos adicionales.\n";
                    $mensaje .= "¿Dudas? Contáctanos.";

                    // Limitar mensaje a 300 caracteres
                    $mensaje = substr($mensaje, 0, 300);

                    try {
                        $this->whatsapp->sendMessage($empeno->cliente->telefono, $mensaje);
                        $enviados++;
                    } catch (\Exception $e) {
                        $errores[] = [
                            'empeno_id' => $empeno->id_empeno,
                            'telefono' => $empeno->cliente->telefono,
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Recordatorios enviados',
                'data' => [
                    'enviados' => $enviados,
                    'errores' => $errores
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error al enviar recordatorios: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar recordatorios: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener clientes de la empresa
     * GET /api/empenos/clientes
     */
    public function getClientes(Request $request)
    {
        try {
            $user = $request->user();
            
            $clientes = Cliente::where('id_empresa', $user->id_empresa)
                ->where('activo', 1)
                ->select('id_cliente', 'nombre', 'apellido', 'telefono', 'correo')
                ->orderBy('nombre')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $clientes
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener prendas disponibles
     * GET /api/empenos/prendas-disponibles
     */
    public function getPrendasDisponibles(Request $request)
    {
        try {
            $user = $request->user();
            
            $prendas = Prenda::where('id_empresa', $user->id_empresa)
                ->where('estado', 'Disponible')
                ->select('id_prenda', 'descripcion', 'tipo', 'valor_estimado')
                ->orderBy('descripcion')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $prendas
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener tasas de interés activas
     * GET /api/empenos/tasas
     */
    public function getTasas(Request $request)
    {
        try {
            $tasas = TasaInteres::where('activo', 1)
                ->select('id_tasa', 'nombre', 'porcentaje', 'plazo_dias')
                ->orderBy('porcentaje')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $tasas
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear una prenda rápidamente
     * POST /api/empenos/prendas
     */
    public function storePrenda(Request $request)
    {
        try {
            $user = $request->user();
            
            $validated = $request->validate([
                'descripcion' => 'required|string|max:255',
                'tipo' => 'required|string',
                'material' => 'nullable|string',
                'peso_gramos' => 'nullable|numeric',
                'valor_estimado' => 'required|numeric|min:1',
            ]);
            
            $prenda = Prenda::create([
                'id_empresa' => $user->id_empresa,
                'descripcion' => $validated['descripcion'],
                'tipo' => $validated['tipo'],
                'material' => $validated['material'] ?? null,
                'peso_gramos' => $validated['peso_gramos'] ?? null,
                'valor_estimado' => $validated['valor_estimado'],
                'estado' => 'Disponible',
                'codigo_barras' => 'PRN-' . strtoupper(uniqid()),
                'fecha_registro' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Prenda creada correctamente',
                'data' => $prenda
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear prenda: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de empeños
     * GET /api/empenos/estadisticas
     */
    public function estadisticas(Request $request)
    {
        try {
            $user = $request->user();
            $empresaId = $user->id_empresa;

            $stats = [
                'total' => Empeno::where('id_empresa', $empresaId)->count(),
                'activos' => Empeno::where('id_empresa', $empresaId)->where('estado', 'activo')->count(),
                'vencidos' => Empeno::where('id_empresa', $empresaId)->where('estado', 'vencido')->count(),
                'recuperados' => Empeno::where('id_empresa', $empresaId)->where('estado', 'recuperado')->count(),
                'monto_total_prestado' => Empeno::where('id_empresa', $empresaId)->sum('monto_prestado'),
                'por_vencer' => Empeno::where('id_empresa', $empresaId)
                    ->where('estado', 'activo')
                    ->whereBetween('fecha_vencimiento', [now(), now()->addDays(3)])
                    ->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}