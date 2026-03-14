<?php

namespace App\Http\Controllers\Api;

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

    public function index()
    {
        $clientes = Cliente::select(
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
    )->get();

    return response()->json($clientes);
    }


public function store(Request $request)
{
    try {
        // Validación
        $request->validate([
            'nombre'=>'required|string|max:100',
            'telefono'=>'required|string|max:20',
           'correo'=>'nullable|email|unique:usuario,correo',
            'direccion'=>'nullable|string|max:255',
            'codigo_postal'=>'nullable|string|max:10',
            'tipo_identificacion'=>'required',
            'numero_identificacion'=>'required',
            'contrasena'=>'nullable|string|min:6', // opcional
        ]);

        // Crear usuario primero
        $usuario = Usuario::create([
            'nombre' => $request->nombre,
            'correo' => $request->correo ?? 'user' . time() . '@example.com', // si no hay correo
            'contrasena' => Hash::make($request->contrasena ?? '123456'), // contraseña por defecto si no viene
            'telefono' => $request->telefono,
            'id_rol' => 4, // por ejemplo 4 = cliente
            'activo' => 1,
            'fecha_registro' => now(),
        ]);

        // Subir fotos si existen
        $fotoPerfil = $request->hasFile('foto_perfil') ? $request->file('foto_perfil')->store('clientes','public') : null;
        $fotoIne = $request->hasFile('foto_ine') ? $request->file('foto_ine')->store('ine','public') : null;

        // Crear cliente asociado al usuario
        $cliente = Cliente::create([
           'id_usuario' => $usuario->id_usuario,
            'nombre' => $request->nombre,
            'apellido' => $request->apellido ?? '',
            'telefono' => $request->telefono,
            'correo' => $request->correo ?? '',
            'direccion' => $request->direccion ?? '',
            'codigo_postal' => $request->codigo_postal ?? '',
            'ciudad' => $request->ciudad ?? '',
            'estado' => $request->estado ?? '',
            'fecha_registro' => now(),
            'tipo_identificacion' => $request->tipo_identificacion,
            'numero_identificacion' => $request->numero_identificacion,
            'foto_perfil' => $fotoPerfil,
            'foto_ine' => $fotoIne,
        ]);

        return response()->json([
            "mensaje"=>"Cliente y usuario creados correctamente",
            "cliente"=>$cliente,
            "usuario"=>$usuario
        ],201);

    } catch (\Exception $e) {
    return response()->json([
        "mensaje" => "Error al crear cliente",
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ],500);
}
}


public function show($id)
{
    try {
        $cliente = Cliente::with(['empenos.pagos'])->findOrFail($id);

        // Asegurarse de que siempre existan arrays para React
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
            'empenos' => $cliente->empenos->map(function($empeno){
                return [
                    'id_empeno' => $empeno->id_empreno,
                    'fecha_empeno' => $empeno->fecha_empreno,
                    'descripcion' => $empeno->descripcion ?? '',
                    'monto' => $empeno->monto_prestado,
                    'pagos' => $empeno->pagos->map(function($pago){
                        return [
                            'id_pago' => $pago->id_pago,
                            'fecha_pago' => $pago->fecha_pago,
                            'monto' => $pago->monto,
                            'tipo_pago' => $pago->tipo_pago ?? '',
                            'metodo_pago' => $pago->metodo_pago ?? '',
                            'referencia' => $pago->referencia ?? ''
                        ];
                    })->values()
                ];
            })->values(),
            'pagos' => $cliente->empenos->flatMap(function($e){ return $e->pagos; })->values()
        ];

        return response()->json($clienteData);

    } catch (\Exception $e) {
        return response()->json([
            "mensaje" => "Error al cargar detalles del cliente",
            "error" => $e->getMessage()
        ], 500);
    }
}
 public function update(Request $request, $id)
{
    $cliente = Cliente::findOrFail($id);

    // Actualizar fotos si vienen
    if($request->hasFile('foto_perfil')){
        if($cliente->foto_perfil){
            Storage::disk('public')->delete($cliente->foto_perfil);
        }
        $cliente->foto_perfil = $request->file('foto_perfil')->store('clientes','public');
    }

    if($request->hasFile('foto_ine')){
        if($cliente->foto_ine){
            Storage::disk('public')->delete($cliente->foto_ine);
        }
        $cliente->foto_ine = $request->file('foto_ine')->store('ine','public');
    }

    // Actualizar cliente
    $cliente->update([
    'nombre'=>$request->nombre,
    'apellido'=>$request->apellido ?? '', 
    'telefono'=>$request->telefono,
    'correo'=>$request->correo ?? '',
    'direccion'=>$request->direccion ?? '', // <-- editable
    'codigo_postal'=>$request->codigo_postal ?? '',
    'ciudad'=>$request->ciudad ?? '',       // <-- editable
    'estado'=>$request->estado ?? '',       // <-- editable
    'tipo_identificacion'=>$request->tipo_identificacion,
    'numero_identificacion'=>$request->numero_identificacion
    ]);

    // Actualizar usuario asociado
    if($cliente->usuario){
        $cliente->usuario->update([
            'nombre' => $request->nombre,
            'correo' => $request->correo ?? $cliente->usuario->correo,
            'telefono' => $request->telefono,
        ]);
    }

    return response()->json([
        "mensaje"=>"Cliente y usuario actualizados",
        "cliente"=>$cliente
    ]);
}


    public function destroy($id)
    {
        $cliente = Cliente::findOrFail($id);

        if($cliente->foto_perfil){
            Storage::disk('public')->delete($cliente->foto_perfil);
        }

        if($cliente->foto_ine){
            Storage::disk('public')->delete($cliente->foto_ine);
        }

        $cliente->delete();

        return response()->json([
            "mensaje"=>"Cliente eliminado"
        ]);
    }

public function buscarCP($cp)
{

    try {

        $response = Http::get("https://api.tau.com.mx/dipomex/v1/codigo_postal/$cp");

        if ($response->successful()) {

            $data = $response->json();

            return response()->json([
                "estado" => $data["estado"] ?? "",
                "municipio" => $data["municipio"] ?? "",
                "colonias" => $data["colonias"] ?? []
            ]);

        }

        return response()->json([
            "mensaje" => "Código postal no encontrado"
        ],404);

    } catch (\Exception $e) {

        return response()->json([
            "error" => $e->getMessage()
        ],500);

    }

}

public function historial($id_cliente)
{
    try {
        // Traer cliente con sus empeños y pagos
        $cliente = Cliente::with(['empenos.pagos'])->findOrFail($id_cliente);

        // Transformar datos para incluir saldo pendiente de cada empeño
        $historial = $cliente->empenos->map(function ($empeno) {
            $totalPagos = $empeno->pagos->sum('monto'); // suma de pagos
            $saldoPendiente = $empeno->monto_prestado + $empeno->intereses - $totalPagos;

            return [
                'id_empreno' => $empeno->id_empreno,
                'fecha_empreno' => $empeno->fecha_empreno,
                'monto_prestado' => $empeno->monto_prestado,
                'intereses' => $empeno->intereses,
                'fecha_vencimiento' => $empeno->fecha_vencimiento,
                'estado_empeno' => $empeno->estado,
                'folio' => $empeno->folio,
                'saldo_pendiente' => $saldoPendiente,
                'pagos' => $empeno->pagos->map(function ($pago) {
                    return [
                        'id_pago' => $pago->id_pago,
                        'fecha_pago' => $pago->fecha_pago,
                        'monto' => $pago->monto,
                        'tipo_pago' => $pago->tipo_pago,
                        'metodo_pago' => $pago->metodo_pago,
                        'referencia' => $pago->referencia,
                    ];
                })->sortBy('fecha_pago')->values() // orden por fecha de pago
            ];
        })->sortByDesc('fecha_empreno')->values(); // orden por fecha de empeño

        return response()->json([
            'cliente' => [
                'id_cliente' => $cliente->id_cliente,
                'nombre' => $cliente->nombre,
                'apellido' => $cliente->apellido,
                'telefono' => $cliente->telefono,
                'correo' => $cliente->correo,
            ],
            'historial' => $historial
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'mensaje' => 'Error al obtener historial del cliente',
            'error' => $e->getMessage()
        ], 500);
    }
}

}

