<?php

namespace App\Http\Controllers\API; // ✅ CORREGIDO: API en mayúscula

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;

class ClienteController extends Controller
{
    // ✅ LISTAR CLIENTES
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            $clientes = Cliente::where('id_empresa', $user->id_empresa)
                ->select(
                    'id_cliente',
                    'nombre',
                    'apellido',
                    'telefono',
                    'correo',
                    'direccion',
                    'codigo_postal',
                    'ciudad',
                    'estado',
                    'fecha_registro',
                    'tipo_identificacion',
                    'numero_identificacion',
                    'foto_perfil'
                )
                ->get();

            return response()->json([
                'success' => true,
                'data' => $clientes
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en ClienteController::index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar clientes: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ CREAR CLIENTE
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            $request->validate([
                'nombre' => 'required|string|max:100',
                'apellido' => 'nullable|string|max:100',
                'telefono' => 'required|string|max:20',
                'correo' => 'nullable|email|unique:usuario,correo',
                'direccion' => 'nullable|string|max:255',
                'codigo_postal' => 'nullable|string|max:10',
                'tipo_identificacion' => 'nullable|string',
                'numero_identificacion' => 'nullable|string',
                'contrasena' => 'nullable|string|min:6',
            ]);

            // Crear usuario
            $usuario = Usuario::create([
                'nombre' => $request->nombre . ' ' . ($request->apellido ?? ''),
                'correo' => $request->correo ?? 'user' . time() . '@example.com',
                'contrasena' => Hash::make($request->contrasena ?? '123456'),
                'telefono' => $request->telefono,
                'id_rol' => 4, // rol cliente
                'id_empresa' => $user->id_empresa,
                'activo' => 1,
                'fecha_registro' => now(),
            ]);

            // Subir fotos
            $fotoPerfil = null;
            if ($request->hasFile('foto_perfil')) {
                $fotoPerfil = $request->file('foto_perfil')->store('clientes', 'public');
            }
            
            $fotoIne = null;
            if ($request->hasFile('foto_ine')) {
                $fotoIne = $request->file('foto_ine')->store('ine', 'public');
            }

            // Crear cliente
            $cliente = Cliente::create([
                'id_usuario' => $usuario->id_usuario,
                'id_empresa' => $user->id_empresa,
                'nombre' => $request->nombre,
                'apellido' => $request->apellido ?? '',
                'telefono' => $request->telefono,
                'correo' => $request->correo ?? '',
                'direccion' => $request->direccion ?? '',
                'codigo_postal' => $request->codigo_postal ?? '',
                'ciudad' => $request->ciudad ?? '',
                'estado' => $request->estado ?? '',
                'fecha_registro' => now(),
                'activo' => 1,
                'tipo_identificacion' => $request->tipo_identificacion ?? null,
                'numero_identificacion' => $request->numero_identificacion ?? null,
                'foto_perfil' => $fotoPerfil,
                'foto_ine' => $fotoIne,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cliente creado correctamente',
                'data' => [
                    'cliente' => $cliente,
                    'usuario' => $usuario
                ]
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Error en ClienteController::store: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ MOSTRAR CLIENTE
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $cliente = Cliente::where('id_empresa', $user->id_empresa)
                ->with(['empenos.pagos', 'usuario'])
                ->findOrFail($id);

            // Corregir relaciones: id_empeno en lugar de id_empreno
            $clienteData = [
                'id_cliente' => $cliente->id_cliente,
                'nombre' => $cliente->nombre,
                'apellido' => $cliente->apellido,
                'telefono' => $cliente->telefono,
                'email' => $cliente->correo,
                'direccion' => $cliente->direccion ?? '',
                'ciudad' => $cliente->ciudad ?? '',
                'codigoPostal' => $cliente->codigo_postal ?? '',
                'tipoIdentificacion' => $cliente->tipo_identificacion ?? 'INE',
                'numeroIdentificacion' => $cliente->numero_identificacion ?? '',
                'fecha' => $cliente->fecha_registro ?? '',
                'empenos' => $cliente->empenos->map(function($empeno) {
                    return [
                        'id_empeno' => $empeno->id_empeno, // ✅ CORREGIDO
                        'fecha_empeno' => $empeno->fecha_empeno,
                        'monto' => $empeno->monto_prestado,
                        'estado' => $empeno->estado,
                        'pagos' => $empeno->pagos->map(function($pago) {
                            return [
                                'id_pago' => $pago->id_pago,
                                'fecha_pago' => $pago->fecha_pago,
                                'monto' => $pago->monto_total,
                                'tipo_pago' => $pago->tipo_pago ?? '',
                                'metodo_pago' => $pago->metodo_pago ?? '',
                                'referencia' => $pago->referencia ?? ''
                            ];
                        })->values()
                    ];
                })->values()
            ];

            return response()->json([
                'success' => true,
                'data' => $clienteData
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en ClienteController::show: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ ACTUALIZAR CLIENTE
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $cliente = Cliente::where('id_empresa', $user->id_empresa)
                ->findOrFail($id);

            // Subir fotos
            if ($request->hasFile('foto_perfil')) {
                if ($cliente->foto_perfil) {
                    Storage::disk('public')->delete($cliente->foto_perfil);
                }
                $cliente->foto_perfil = $request->file('foto_perfil')->store('clientes', 'public');
            }

            if ($request->hasFile('foto_ine')) {
                if ($cliente->foto_ine) {
                    Storage::disk('public')->delete($cliente->foto_ine);
                }
                $cliente->foto_ine = $request->file('foto_ine')->store('ine', 'public');
            }

            // Actualizar cliente
            $cliente->update([
                'nombre' => $request->nombre ?? $cliente->nombre,
                'apellido' => $request->apellido ?? $cliente->apellido,
                'telefono' => $request->telefono ?? $cliente->telefono,
                'correo' => $request->correo ?? $cliente->correo,
                'direccion' => $request->direccion ?? $cliente->direccion,
                'codigo_postal' => $request->codigo_postal ?? $cliente->codigo_postal,
                'ciudad' => $request->ciudad ?? $cliente->ciudad,
                'estado' => $request->estado ?? $cliente->estado,
                'tipo_identificacion' => $request->tipo_identificacion ?? $cliente->tipo_identificacion,
                'numero_identificacion' => $request->numero_identificacion ?? $cliente->numero_identificacion,
            ]);

            // Actualizar usuario asociado
            if ($cliente->usuario) {
                $cliente->usuario->update([
                    'nombre' => $request->nombre . ' ' . ($request->apellido ?? ''),
                    'correo' => $request->correo ?? $cliente->usuario->correo,
                    'telefono' => $request->telefono ?? $cliente->usuario->telefono,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cliente actualizado correctamente',
                'data' => $cliente
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en ClienteController::update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ ELIMINAR CLIENTE
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $cliente = Cliente::where('id_empresa', $user->id_empresa)
                ->findOrFail($id);

            if ($cliente->foto_perfil) {
                Storage::disk('public')->delete($cliente->foto_perfil);
            }

            if ($cliente->foto_ine) {
                Storage::disk('public')->delete($cliente->foto_ine);
            }

            // Eliminar usuario asociado
            if ($cliente->usuario) {
                $cliente->usuario->delete();
            }

            $cliente->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cliente eliminado correctamente'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en ClienteController::destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ HISTORIAL
    public function historial(Request $request, $id_cliente)
    {
        try {
            $user = $request->user();
            
            $cliente = Cliente::where('id_empresa', $user->id_empresa)
                ->with(['empenos.pagos'])
                ->findOrFail($id_cliente);

            $historial = $cliente->empenos->map(function ($empeno) {
                $totalPagos = $empeno->pagos->sum('monto_total');
                $saldoPendiente = $empeno->monto_prestado + ($empeno->intereses ?? 0) - $totalPagos;

                return [
                    'id_empeno' => $empeno->id_empeno, // ✅ CORREGIDO
                    'fecha_empeno' => $empeno->fecha_empeno,
                    'monto_prestado' => $empeno->monto_prestado,
                    'intereses' => $empeno->intereses ?? 0,
                    'fecha_vencimiento' => $empeno->fecha_vencimiento,
                    'estado_empeno' => $empeno->estado,
                    'folio' => $empeno->folio,
                    'saldo_pendiente' => $saldoPendiente,
                    'pagos' => $empeno->pagos->map(function ($pago) {
                        return [
                            'id_pago' => $pago->id_pago,
                            'fecha_pago' => $pago->fecha_pago,
                            'monto' => $pago->monto_total,
                            'tipo_pago' => $pago->tipo_pago,
                            'metodo_pago' => $pago->metodo_pago,
                            'referencia' => $pago->referencia,
                        ];
                    })->sortBy('fecha_pago')->values()
                ];
            })->sortByDesc('fecha_empeno')->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'cliente' => [
                        'id_cliente' => $cliente->id_cliente,
                        'nombre' => $cliente->nombre,
                        'apellido' => $cliente->apellido,
                        'telefono' => $cliente->telefono,
                        'correo' => $cliente->correo,
                    ],
                    'historial' => $historial
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en ClienteController::historial: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener historial: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ BUSCAR CP
    public function buscarCP($cp)
    {
        try {
            $response = Http::get("https://api.tau.com.mx/dipomex/v1/codigo_postal/$cp");

            if ($response->successful()) {
                $data = $response->json();
                return response()->json([
                    'success' => true,
                    'data' => [
                        'estado' => $data["estado"] ?? "",
                        'municipio' => $data["municipio"] ?? "",
                        'colonias' => $data["colonias"] ?? []
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Código postal no encontrado'
            ], 404);

        } catch (\Exception $e) {
            \Log::error('Error en ClienteController::buscarCP: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al buscar CP: ' . $e->getMessage()
            ], 500);
        }
    }
}