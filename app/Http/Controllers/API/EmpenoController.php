<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Empeno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Prenda;
use App\Models\Cliente;
use App\Models\ImagenPrenda;
use Carbon\Carbon;

class EmpenoController extends Controller
{
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
     * Crear prenda (con imagen opcional en Cloudinary)
     * FIX: se agregó DB::beginTransaction() — antes faltaba y el DB::commit()
     * tronaba porque no había ninguna transacción abierta, rompiendo el guardado
     * de la imagen.
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
                'imagen_url' => 'nullable|url|max:500',
            ]);

            DB::beginTransaction(); // <-- FIX: esto faltaba

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

            // GUARDAR IMAGEN EN imagen_prenda
            if (!empty($validated['imagen_url'])) {
                ImagenPrenda::create([
                    'id_prenda' => $prenda->id_prenda,
                    'cloudinary_url' => $validated['imagen_url'],
                    'es_principal' => true,
                    'orden' => 0,
                ]);
            }

            DB::commit();

            // CARGAR LA IMAGEN PARA DEVOLVERLA
            $prenda->load('imagenPrincipal');
            $prenda->imagen_url = $validated['imagen_url'] ?? null;

            return response()->json([
                'success' => true,
                'message' => 'Prenda creada correctamente' . (!empty($validated['imagen_url']) ? ' con imagen' : ''),
                'data' => $prenda
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear prenda: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear prenda: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * NUEVO: agregar o reemplazar la imagen de una prenda ya existente.
     * POST /api/prendas/{id}/imagen
     */
    public function actualizarImagenPrenda(Request $request, $id)
    {
        try {
            $user = $request->user();

            $prenda = Prenda::where('id_prenda', $id)
                ->where('id_empresa', $user->id_empresa)
                ->first();

            if (!$prenda) {
                return response()->json([
                    'success' => false,
                    'message' => 'Prenda no encontrada'
                ], 404);
            }

            $validated = $request->validate([
                'imagen_url' => 'required|url|max:500',
            ]);

            DB::beginTransaction();

            // Si ya tenía una imagen, la reemplazamos
            ImagenPrenda::where('id_prenda', $prenda->id_prenda)->delete();

            ImagenPrenda::create([
                'id_prenda' => $prenda->id_prenda,
                'cloudinary_url' => $validated['imagen_url'],
                'es_principal' => true,
                'orden' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Imagen actualizada correctamente',
                'data' => ['imagen_url' => $validated['imagen_url']]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al actualizar imagen de prenda: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar imagen: ' . $e->getMessage()
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
                'aval_id' => 'nullable|exists:aval,id_aval',
                'plazo_meses' => 'required|integer|min:1|max:6'
            ]);

            $tasa = DB::table('tasas_interes')->where('id_tasa', $validated['tasa_id'])->first();
             $prenda = Prenda::find($validated['prenda_id']);
             $material = $prenda ? $prenda->material : null;

            $interesMonto = $validated['monto_prestado'] * ($tasa->porcentaje / 100) * $validated['plazo_meses'];
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
                'intereses' => $interesMonto,
                'iva_porcentaje' => 16.00,
                'fecha_vencimiento' => $validated['fecha_vencimiento'],
                'plazo_meses' => $validated['plazo_meses'],
                'estado' => 'activo',
                'folio' => $folio,
                'material' => $material 
            ], 'id_empeno');

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
            ], 'id_amortizacion');

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
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar empeño: ' . $e->getMessage()
            ], 500);
        }
    }

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

    public function getPrendasDisponibles(Request $request)
    {
        try {
            $user = $request->user();

            $prendas = DB::table('prendas')
                ->where('id_empresa', $user->id_empresa)
                ->where('estado', 'Disponible')
                ->where('origen', 'empeno')
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
     * FIX: se agregó eager load de la imagen de la prenda y se incluyen
     * 'id_prenda' e 'imagen_url' en cada resultado, para que el listado
     * (EmpenosLista.jsx) pueda mostrar y editar la imagen.
     */
    public function todos(Request $request)
    {
        try {
            $user = $request->user();

            $empenos = Empeno::where('id_empresa', $user->id_empresa)
                ->with(['cliente', 'prenda', 'prenda.imagenPrincipal'])
                ->withSum('pagos as total_pagado', 'monto_total')
                ->orderBy('fecha_empeno', 'desc')
                ->orderBy('id_empeno', 'desc')
                ->get();

            $resultados = [];

            foreach ($empenos as $empeno) {
                $totalPagado = $empeno->total_pagado ?? 0;

                $saldoTotalPendiente = max(0, ($empeno->monto_prestado ?? 0) - $totalPagado);

                $estadoReal = $empeno->estado_real;
                $diasVencidos = $empeno->dias_vencidos;

                $imagenUrl = null;
                if ($empeno->prenda && $empeno->prenda->imagenPrincipal) {
                    if (!empty($empeno->prenda->imagenPrincipal->cloudinary_url)) {
                        $imagenUrl = $empeno->prenda->imagenPrincipal->cloudinary_url;
                    } elseif (!empty($empeno->prenda->imagenPrincipal->imagen_data)) {
                        $imagenUrl = url('/api/imagen-prenda/' . $empeno->prenda->id_prenda);
                    }
                }

                $resultados[] = [
                    'id_empeno' => $empeno->id_empeno,
                    'id_prenda' => $empeno->id_prenda,
                    'cliente' => $empeno->cliente ? $empeno->cliente->nombre . ' ' . $empeno->cliente->apellido : 'Cliente no disponible',
                    'articulo' => $empeno->prenda ? $empeno->prenda->descripcion : 'Sin artículo',
                    'imagen_url' => $imagenUrl,
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

    public function actualizarEstados(Request $request)
    {
        try {
            $user = $request->user();

            $actualizados = Empeno::where('id_empresa', $user->id_empresa)
                ->where('estado', 'activo')
                ->whereDate('fecha_vencimiento', '<', now()->toDateString())
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
}