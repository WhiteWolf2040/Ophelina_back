<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Prenda;
use App\Models\ImagenPrenda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PrendaController extends Controller
{
    private const TIPOS_VALIDOS = ['Joyería', 'Electrónica', 'Relojes', 'Herramientas', 'Instrumentos', 'Otros'];
    private const ESTADOS_VALIDOS = ['Disponible', 'En Empeño', 'Vendido', 'Vencido', 'Apartado'];

    /**
     * ✅ NUEVO: única fuente de verdad para guardar la imagen principal de
     * una prenda. Antes, store()/update() guardaban solo en prendas.imagen_url,
     * columna que Tienda y MisEmpeños NUNCA leen (ellos leen imagen_prenda
     * con es_principal=true). Ahora todo pasa por aquí, así que subir una
     * imagen desde Inventario también la hace visible en Tienda/MisEmpeños.
     */
    private function upsertImagenPrincipal(int $idPrenda, string $url): void
    {
        $existente = ImagenPrenda::where('id_prenda', $idPrenda)
            ->where('es_principal', true)
            ->first();

        if ($existente) {
            $existente->update([
                'cloudinary_url' => $url,
                'imagen_data' => null,
                'imagen_mime' => null,
            ]);
        } else {
            ImagenPrenda::create([
                'id_prenda' => $idPrenda,
                'cloudinary_url' => $url,
                'es_principal' => true,
                'orden' => 0,
            ]);
        }
    }

    /**
     * ✅ NUEVO: resuelve la imagen igual que TiendaController/MisEmpenosController,
     * para que Inventario muestre exactamente lo mismo que ven Tienda y el cliente.
     */
    private function resolverImagenUrl($idPrenda): ?string
    {
        $imagen = ImagenPrenda::where('id_prenda', $idPrenda)->where('es_principal', true)->first();
        if (!$imagen) {
            $imagen = ImagenPrenda::where('id_prenda', $idPrenda)->first();
        }
        if (!$imagen) return null;

        if (!empty($imagen->cloudinary_url)) return $imagen->cloudinary_url;
        if (!empty($imagen->imagen_data)) return url('/api/imagen-prenda/' . $idPrenda);
        return null;
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }

            $prendas = Prenda::where('id_empresa', $user->id_empresa)
                ->orderBy('fecha_registro', 'desc')
                ->get();

            // ✅ NUEVO: se sobreescribe imagen_url con la fuente real
            // (imagen_prenda), para que el frontend siempre reciba lo correcto.
            $prendas->each(function (Prenda $p) {
                $p->imagen_url = $this->resolverImagenUrl($p->id_prenda);
            });

            return response()->json(['success' => true, 'data' => $prendas]);

        } catch (\Throwable $e) {
            Log::error('Error en PrendaController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar inventario: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = request()->user();

            $prenda = Prenda::where('id_prenda', $id)
                ->where('id_empresa', $user->id_empresa)
                ->with(['empenos', 'producto_tienda'])
                ->firstOrFail();

            $prenda->imagen_url = $this->resolverImagenUrl($prenda->id_prenda);

            return response()->json([
                'success' => true,
                'data' => [
                    'inventario' => $prenda,
                    'tienda' => $prenda->producto_tienda,
                    'empeno' => $prenda->empenos->first()
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Prenda no encontrada'], 404);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }

            $validated = $request->validate([
                'descripcion' => 'required|string|max:255',
                'tipo' => 'required|string|in:' . implode(',', self::TIPOS_VALIDOS),
                'material' => 'nullable|string',
                'peso_gramos' => 'nullable|numeric',
                'valor_estimado' => 'required|numeric|min:1',
                'estado' => 'nullable|string|in:' . implode(',', self::ESTADOS_VALIDOS),
                'imagen_url' => 'nullable|url|max:255',
            ]);

            $prenda = Prenda::create([
                'id_empresa' => $user->id_empresa,
                'descripcion' => $validated['descripcion'],
                'tipo' => $validated['tipo'],
                'material' => $validated['material'] ?? null,
                'peso_gramos' => $validated['peso_gramos'] ?? null,
                'valor_estimado' => $validated['valor_estimado'],
                'estado' => $validated['estado'] ?? 'Disponible',
                'codigo_barras' => 'PRN-' . strtoupper(uniqid()),
                'fecha_registro' => now()
            ]);

            // ✅ NUEVO: si viene imagen, se guarda TAMBIÉN en imagen_prenda
            if (!empty($validated['imagen_url'])) {
                $this->upsertImagenPrincipal($prenda->id_prenda, $validated['imagen_url']);
            }

            $prenda->imagen_url = $this->resolverImagenUrl($prenda->id_prenda);

            return response()->json([
                'success' => true,
                'message' => 'Prenda creada correctamente',
                'data' => $prenda
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Error en PrendaController@store: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al crear prenda: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();

            $prenda = Prenda::where('id_prenda', $id)
                ->where('id_empresa', $user->id_empresa)
                ->firstOrFail();

            $validated = $request->validate([
                'descripcion' => 'required|string|max:255',
                'tipo' => 'required|string|in:' . implode(',', self::TIPOS_VALIDOS),
                'material' => 'nullable|string',
                'peso_gramos' => 'nullable|numeric',
                'valor_estimado' => 'required|numeric|min:1',
                'estado' => 'nullable|string|in:' . implode(',', self::ESTADOS_VALIDOS),
                'imagen_url' => 'nullable|url|max:255',
            ]);

            $imagenUrl = $validated['imagen_url'] ?? null;
            unset($validated['imagen_url']); // no se guarda en prendas, se va a imagen_prenda

            $prenda->update($validated);

            // ✅ NUEVO
            if (!empty($imagenUrl)) {
                $this->upsertImagenPrincipal($prenda->id_prenda, $imagenUrl);
            }

            $prenda->imagen_url = $this->resolverImagenUrl($prenda->id_prenda);

            return response()->json([
                'success' => true,
                'message' => 'Prenda actualizada correctamente',
                'data' => $prenda
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Error en PrendaController@update: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al actualizar prenda: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = request()->user();
            $prenda = Prenda::where('id_prenda', $id)->where('id_empresa', $user->id_empresa)->firstOrFail();
            $prenda->delete();
            return response()->json(['success' => true, 'message' => 'Prenda eliminada correctamente']);
        } catch (\Throwable $e) {
            Log::error('Error en PrendaController@destroy: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al eliminar prenda: ' . $e->getMessage()], 500);
        }
    }

    /**
     * ✅ NUEVO: asignación masiva de imágenes. El frontend sube cada archivo
     * a Cloudinary (igual que ya hace en otros formularios) y aquí solo se
     * registran todas las URLs resultantes de un jalón, en una transacción.
     *
     * POST /api/prendas/bulk-imagenes
     * body: { asignaciones: [{ id_prenda: 12, imagen_url: "https://..." }, ...] }
     */
    public function bulkAsignarImagenes(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }

            $validated = $request->validate([
                'asignaciones' => 'required|array|min:1',
                'asignaciones.*.id_prenda' => 'required|integer|exists:prendas,id_prenda',
                'asignaciones.*.imagen_url' => 'required|url|max:500',
            ]);

            $idsPermitidos = Prenda::where('id_empresa', $user->id_empresa)
                ->pluck('id_prenda')
                ->toArray();

            $asignados = 0;
            $rechazados = [];

            DB::beginTransaction();

            foreach ($validated['asignaciones'] as $item) {
                // seguridad: solo prendas de la propia empresa
                if (!in_array((int) $item['id_prenda'], $idsPermitidos, true)) {
                    $rechazados[] = $item['id_prenda'];
                    continue;
                }

                $this->upsertImagenPrincipal((int) $item['id_prenda'], $item['imagen_url']);
                $asignados++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$asignados} imagen(es) asignada(s) correctamente",
                'asignados' => $asignados,
                'rechazados' => $rechazados,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error en PrendaController@bulkAsignarImagenes: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al asignar imágenes: ' . $e->getMessage()], 500);
        }
    }
}