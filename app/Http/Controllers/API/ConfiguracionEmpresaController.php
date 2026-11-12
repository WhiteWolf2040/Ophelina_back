<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CategoriaPrenda;
use App\Models\Empresa;
use App\Models\EmpresaConfiguracion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfiguracionEmpresaController extends Controller
{
    private const CATEGORIAS_DEFAULT = [
        ['Anillos de oro', 65],
        ['Cadenas y pulseras', 60],
        ['Relojes', 45],
        ['Electrónicos', 40],
        ['Celulares', 50],
    ];

    /**
     * Devuelve la configuración de la empresa del usuario logueado.
     * Si la empresa es nueva (no tiene fila), se crea con valores por
     * defecto y se le cargan las categorías base.
     */
    private function config(Request $request): EmpresaConfiguracion
    {
        $idEmpresa = $request->user()->id_empresa;

        $config = EmpresaConfiguracion::firstOrCreate(['id_empresa' => $idEmpresa]);

        if ($config->wasRecentlyCreated
            && !CategoriaPrenda::where('id_empresa', $idEmpresa)->exists()) {
            foreach (self::CATEGORIAS_DEFAULT as [$nombre, $pct]) {
                CategoriaPrenda::create([
                    'id_empresa' => $idEmpresa,
                    'nombre'     => $nombre,
                    'avaluo_pct' => $pct,
                ]);
            }
        }

        return $config->fresh();
    }

    public function index(Request $request)
    {
        $config    = $this->config($request);
        $idEmpresa = $config->id_empresa;
        $empresa   = Empresa::where('id_empresa', $idEmpresa)->first();

        $domicilio = collect([
            $empresa?->direccion, $empresa?->colonia, $empresa?->ciudad, $empresa?->estado,
        ])->filter()->implode(', ');

        return response()->json([
            'config'     => $config,
            'categorias' => CategoriaPrenda::where('id_empresa', $idEmpresa)
                                ->orderBy('id_categoria')->get(),

            // Datos fiscales que ya viven en la tabla empresa
            'fiscal' => [
                'rfc'           => $empresa?->rfc,
                'razon_social'  => $empresa?->nombre,
                'domicilio'     => $domicilio,
                'codigo_postal' => $empresa?->codigo_postal,
            ],

            // Actividad real de avisos (columnas que ya existen en empeno/clientes)
            'notificaciones_stats' => [
                'clientes_whatsapp' => DB::table('clientes')
                    ->where('id_empresa', $idEmpresa)
                    ->where('acepta_whatsapp', true)->count(),
                'avisos_whatsapp_enviados' => DB::table('empeno')
                    ->where('id_empresa', $idEmpresa)
                    ->whereNotNull('aviso_whatsapp_at')->count(),
                'ultimo_aviso_whatsapp' => DB::table('empeno')
                    ->where('id_empresa', $idEmpresa)
                    ->max('aviso_whatsapp_at'),
            ],

          'seguridad' => [
                'ultimo_acceso' => $request->user()->ultimo_acceso
                    ?? data_get($request->user()->currentAccessToken(), 'created_at'),
            ],
        ]);
    }

    // ---------- Operación (plazos, mora, avalúo) ----------
    public function operacion(Request $request)
    {
        $config = $this->config($request);

        $data = $request->validate([
            'plazo_base_dias'         => 'sometimes|required|integer|min:1|max:730',
            'dias_gracia'             => 'sometimes|required|integer|min:0|max:365',
            'aviso_vencimiento_dias'  => 'sometimes|required|integer|min:0|max:90',
            'frecuencia_amortizacion' => 'sometimes|required|in:semanal,quincenal,mensual',
            'interes_moratorio'       => 'sometimes|required|numeric|min:0|max:100',
            'recargo_fijo_dia'        => 'sometimes|required|numeric|min:0|max:100000',
            'castigo_venta_pct'       => 'sometimes|required|numeric|min:0|max:100',
            'max_pct_avaluo'          => 'sometimes|required|numeric|min:1|max:100',
            'monto_min_operacion'     => 'sometimes|required|numeric|min:0',
            'monto_max_operacion'     => 'sometimes|required|numeric|gte:monto_min_operacion',
        ]);

        $config->update($data);
        return response()->json($config->fresh());
    }

    // ---------- Fiscal ----------
    public function fiscal(Request $request)
    {
        $config = $this->config($request);

        $data = $request->validate([
            'regimen_fiscal'    => 'nullable|string|max:100',
            'email_facturacion' => 'nullable|email|max:100',
        ]);

        $config->update($data);
        return response()->json($config->fresh());
    }

    // ---------- Notificaciones ----------
    public function notificaciones(Request $request)
    {
        $config = $this->config($request);

        $data = $request->validate([
            'notif_email_activo'             => 'sometimes|boolean',
            'notif_email_dias'               => 'sometimes|integer|min:0|max:90',
            'notif_whatsapp_activo'          => 'sometimes|boolean',
            'notif_whatsapp_dias'            => 'sometimes|integer|min:0|max:90',
            'notif_dueno_activo'             => 'sometimes|boolean',
            'notif_dueno_hora'               => 'sometimes|date_format:H:i',
            'notif_recordatorio_pago_activo' => 'sometimes|boolean',
            'notif_recordatorio_pago_dias'   => 'sometimes|integer|min:0|max:30',
            'notif_stock_bajo_activo'        => 'sometimes|boolean',
        ]);

        $config->update($data);
        return response()->json($config->fresh());
    }

    // ---------- Portal del cliente ----------
    public function portal(Request $request)
    {
        $config = $this->config($request);

        $data = $request->validate([
            'portal_activo'   => 'sometimes|boolean',
            'portal_mensaje'  => 'nullable|string|max:500',
            'portal_campos'   => 'sometimes|array',
            'portal_campos.*' => 'in:prenda,monto,vencimiento,saldo',
        ]);

        $config->update($data);
        return response()->json($config->fresh());
    }

    // ---------- Categorías de prendas ----------
    private function reglasCategoria(): array
    {
        return [
            'nombre'     => 'required|string|max:100',
            'avaluo_pct' => 'required|numeric|min:0|max:100',
            'activa'     => 'boolean',
        ];
    }

    private function categorias(Request $request)
    {
        return CategoriaPrenda::where('id_empresa', $request->user()->id_empresa);
    }

    public function storeCategoria(Request $request)
    {
        $data = $request->validate($this->reglasCategoria());
        $data['id_empresa'] = $request->user()->id_empresa;

        return response()->json(CategoriaPrenda::create($data)->fresh(), 201);
    }

    public function updateCategoria(Request $request, $id)
    {
        $cat = $this->categorias($request)->findOrFail($id);
        $cat->update($request->validate($this->reglasCategoria()));

        return response()->json($cat);
    }

    public function destroyCategoria(Request $request, $id)
    {
        $this->categorias($request)->findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }
}