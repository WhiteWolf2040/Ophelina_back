<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaConfiguracion extends Model
{
    protected $table = 'empresa_configuracion';
    protected $primaryKey = 'id_config';

    // Solo existe updated_at
    const CREATED_AT = null;
    const UPDATED_AT = 'updated_at';

    protected $guarded = ['id_config'];

    protected $casts = [
        'plazo_base_dias'                => 'integer',
        'dias_gracia'                    => 'integer',
        'aviso_vencimiento_dias'         => 'integer',
        'interes_moratorio'              => 'float',
        'recargo_fijo_dia'               => 'float',
        'castigo_venta_pct'              => 'float',
        'max_pct_avaluo'                 => 'float',
        'monto_min_operacion'            => 'float',
        'monto_max_operacion'            => 'float',
        'notif_email_activo'             => 'boolean',
        'notif_email_dias'               => 'integer',
        'notif_whatsapp_activo'          => 'boolean',
        'notif_whatsapp_dias'            => 'integer',
        'notif_dueno_activo'             => 'boolean',
        'notif_recordatorio_pago_activo' => 'boolean',
        'notif_recordatorio_pago_dias'   => 'integer',
        'notif_stock_bajo_activo'        => 'boolean',
        'portal_activo'                  => 'boolean',
        'portal_campos'                  => 'array',
    ];
}