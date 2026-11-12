<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use Illuminate\Http\Request;

class SucursalController extends Controller
{
    private function query(Request $request)
    {
        return Sucursal::where('id_empresa', $request->user()->id_empresa);
    }

    private function reglas(): array
    {
        return [
            'nombre'    => 'required|string|max:100',
            'encargado' => 'nullable|string|max:100',
            'telefono'  => 'nullable|string|max:20',
            'direccion' => 'nullable|string',
            'activa'    => 'boolean',
        ];
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->query($request)->orderByDesc('es_matriz')->orderBy('nombre')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->reglas());
        $data['id_empresa'] = $request->user()->id_empresa;

        return response()->json(Sucursal::create($data)->fresh(), 201);
    }

    public function update(Request $request, $id)
    {
        $sucursal = $this->query($request)->findOrFail($id);
        $sucursal->update($request->validate($this->reglas()));

        return response()->json($sucursal);
    }

    public function destroy(Request $request, $id)
    {
        $sucursal = $this->query($request)->findOrFail($id);

        if ($sucursal->es_matriz) {
            return response()->json(['error' => 'La casa matriz no se puede eliminar.'], 422);
        }

        $sucursal->delete();
        return response()->json(['ok' => true]);
    }
}