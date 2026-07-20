<?php
// app/Http/Controllers/API/EmpenoController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Empeno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Prenda; 
/* use App\Services\WhatsAppService;  */// ✅ Agregado de tu compañera
use App\Models\Cliente;
use Carbon\Carbon;

class EmpenoController extends Controller
{
    /**
     * Obtener todos los empeños (activos y vencidos)
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $empenos = Empeno::where('id_empresa', $user->id_empresa)
                ->with(['cliente', 'prenda'])
                ->orderBy('fecha_empeno', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $empenos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener detalle de un empeño específico
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            $empeno = Empeno::where('id_empresa', $user->id_empresa)
                ->where('id_empeno', $id)
                ->with(['cliente', 'prenda', 'amortizaciones', 'pagos'])
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
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener empeños activos con saldo pendiente para el formulario de pagos
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

            // Obtener todos los empeños (no solo activos) y usar accessors
            $empenos = Empeno::where('id_empresa', $user->id_empresa)
                ->with(['cliente', 'prenda', 'pagos'])
                ->get();

            $resultados = [];

            foreach ($empenos as $empeno) {
                $totalPagado = $empeno->pagos->sum('monto_total') ?? 0;

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

                // Usar accessors del modelo (estado_real, dias_vencidos)
                $estadoReal = $empeno->estado_real;
                $diasVencidos = $empeno->dias_vencidos;

                $resultados[] = [
                    'id_empeno' => $empeno->id_empeno,
                    'cliente' => $empeno->cliente ? $empeno->cliente->nombre . ' ' . $empeno->cliente->apellido : 'Cliente no disponible',
                    'articulo' => $empeno->prenda ? $empeno->prenda->descripcion : 'Sin artículo',
                    'monto_prestado' => floatval($empeno->monto_prestado ?? 0),
                    'total_pagado' => floatval($totalPagado),
                    'saldo_total_pendiente' => floatval($saldoTotalPendiente),
                    'saldo_pendiente_cuota' => floatval($saldoPendienteCuota),
                    'fecha_empeno' => $empeno->fecha_empeno,
                    'fecha_vencimiento' => $empeno->fecha_vencimiento,
                    'estado' => $estadoReal,
                    'dias_vencidos' => $diasVencidos
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $resultados
            ]);

        } catch (\Exception $e) {
            Log::error('Error en activosConSaldo: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empeños: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear una nueva prenda rápidamente
     * POST /api/prendas
     */
  /**
 * Crear una nueva prenda rápidamente
 * POST /api/prendas
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

        // ✅ Usar el modelo Prenda (no DB::table)
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
        \Log::error('Error al crear prenda: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al crear prenda: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Registrar un nuevo empeño
     * POST /api/empenos
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'cliente_id' => 'required|exists:clientes,id_cliente',
                'prenda_id' => 'required|exists:prendas,id_prenda',
                'monto_prestado' => 'required|numeric|min:100',
                'tasa_id' => 'required|exists:tasas_interes,id_tasa',
                'fecha_vencimiento' => 'required|date',
                'aval_id' => 'nullable|exists:aval,id_aval'
            ]);

            $tasa = DB::table('tasas_interes')->where('id_tasa', $validated['tasa_id'])->first();

            $interesMonto = $validated['monto_prestado'] * ($tasa->porcentaje / 100);
            $ivaInteres = $interesMonto * 0.16;
            $montoTotal = $validated['monto_prestado'] + $interesMonto + $ivaInteres;

            $folio = 'EMP-' . strtoupper(uniqid());

            DB::beginTransaction();

            $idEmpeno = DB::table('empeno')->insertGetId([
                'id_empresa' => $user->id_empresa,
                'id_cliente' => $validated['cliente_id'],
                'id_prenda' => $validated['prenda_id'],
                'id_aval' => $validated['aval_id'] ?? null,
                'id_tasa' => $validated['tasa_id'],
                'fecha_empeno' => now(),
                'monto_prestado' => $validated['monto_prestado'],
                'intereses' => $tasa->porcentaje,
                'iva_porcentaje' => 16.00,
                'fecha_vencimiento' => $validated['fecha_vencimiento'],
                'estado' => 'activo',
                'folio' => $folio
            ]);

            DB::table('prendas')
                ->where('id_prenda', $validated['prenda_id'])
                ->update(['estado' => 'En Empeño']);

            $idAmortizacion = DB::table('amortizacion')->insertGetId([
                'id_empeno' => $idEmpeno,
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

            DB::table('movimientos_caja')->insert([
                'tipo' => 'prestamo',
                'monto' => $validated['monto_prestado'],
                'descripcion' => 'Préstamo por empeño - Folio: ' . $folio,
                'id_usuario' => $user->id_usuario,
                'fecha' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Empeño registrado correctamente',
                'data' => [
                    'id_empeno' => $idEmpeno,
                    'folio' => $folio,
                    'monto_total' => $montoTotal
                ]
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
     * Obtener clientes de la empresa
     * GET /api/clientes
     */
    public function getClientes(Request $request)
    {
        try {
            $user = $request->user();

            $clientes = DB::table('clientes')
                ->where('id_empresa', $user->id_empresa)
                ->where('activo', 1)
                ->select('id_cliente', 'nombre', 'apellido')
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
     * Obtener prendas disponibles de la empresa
     * GET /api/prendas/disponibles
     */
    public function getPrendasDisponibles(Request $request)
    {
        try {
            $user = $request->user();

            $prendas = DB::table('prendas')
                ->where('id_empresa', $user->id_empresa)
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
     * GET /api/tasas-interes
     */
    public function getTasasInteres(Request $request)
    {
        try {
            $tasas = DB::table('tasas_interes')
                ->where('activo', 1)
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
     * Obtener todos los empeños (incluyendo vencidos)
     * GET /api/empenos/todos
     */
    public function todos(Request $request)
    {
        try {
            $user = $request->user();

            $empenos = Empeno::where('id_empresa', $user->id_empresa)
                ->with(['cliente', 'prenda'])
                ->withSum('pagos as total_pagado', 'monto_total')
                ->orderBy('fecha_empeno', 'desc')
                ->get();

            $resultados = [];

            foreach ($empenos as $empeno) {
                $totalPagado = $empeno->total_pagado ?? 0;

                $saldoTotalPendiente = max(0, ($empeno->monto_prestado ?? 0) - $totalPagado);

                $estadoReal = $empeno->estado_real;
                $diasVencidos = $empeno->dias_vencidos;

                $resultados[] = [
                    'id_empeno' => $empeno->id_empeno,
                    'cliente' => $empeno->cliente ? $empeno->cliente->nombre . ' ' . $empeno->cliente->apellido : 'Cliente no disponible',
                    'articulo' => $empeno->prenda ? $empeno->prenda->descripcion : 'Sin artículo',
                    'monto_prestado' => floatval($empeno->monto_prestado ?? 0),
                    'total_pagado' => floatval($totalPagado),
                    'saldo_total_pendiente' => floatval($saldoTotalPendiente),
                    'fecha_empeno' => $empeno->fecha_empeno,
                    'fecha_vencimiento' => $empeno->fecha_vencimiento,
                    'estado' => $estadoReal,
                    'dias_vencidos' => $diasVencidos,
                    'intereses' => floatval($empeno->intereses ?? 0)
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $resultados
            ]);

        } catch (\Exception $e) {
            Log::error('Error en todos empeños: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empeños: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar empeños vencidos en la base de datos
     * POST /api/empenos/actualizar-estados
     */
    public function actualizarEstados(Request $request)
    {
        try {
            $user = $request->user();

            $actualizados = Empeno::where('id_empresa', $user->id_empresa)
                ->where('estado', 'activo')
                ->whereDate('fecha_vencimiento', '<', now()->toDateString()) // ✅ ajustado a comparación por día
                ->update(['estado' => 'vencido']);

            return response()->json([
                'success' => true,
                'message' => "Se actualizaron {$actualizados} empeños a vencidos",
                'data' => ['actualizados' => $actualizados]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ AGREGADO DE TU COMPAÑERA
     * Enviar recordatorios de vencimiento por WhatsApp
     * (activado, y con el bug de variable corregido: $dias en vez de $diasRestantes)
     */
  /*   public function enviarRecordatoriosVencimiento(Request $request)
    {
        try {
            $whatsapp = new WhatsAppService();

            $empenosPorVencer = Empeno::where('estado', 'activo')
                ->whereBetween('fecha_vencimiento', [Carbon::now()->toDateString(), Carbon::now()->addDays(3)->toDateString()])
                ->with(['cliente', 'prenda'])
                ->get();

            foreach ($empenosPorVencer as $empeno) {
                $dias = now()->startOfDay()->diffInDays($empeno->fecha_vencimiento->startOfDay(), false);

                if ($dias >= 0 && $dias <= 3) {
                    $mensaje = "📢 OPHELINA - Recordatorio\n\n";
                    $mensaje .= "Hola {$empeno->cliente->nombre},\n";
                    $mensaje .= "Tu prenda '{$empeno->prenda->descripcion}' vence en {$dias} días.\n\n";
                    $mensaje .= "Realiza tu pago para evitar cargos adicionales.\n";
                    $mensaje .= "¿Dudas? Contáctanos.";

                    $mensaje = substr($mensaje, 0, 300);

                    $whatsapp->sendMessage($empeno->cliente->telefono, $mensaje);
                }
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Error enviando recordatorios: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    } */
}