<?php
// app/Models/Pago.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    protected $table = 'pagos';
    protected $primaryKey = 'id_pago';
    public $timestamps = false;

    protected $fillable = [
        'id_empeno',
        'id_amortizacion',
        'fecha_pago',
        'capital_pagado',
        'interes_pagado',
        'iva_pagado',
        'monto_total',
        'tipo_pago',
        'metodo_pago',
        'referencia',
        'comprobante',
        'fecha_registro'
    ];

    protected $casts = [
        'capital_pagado' => 'decimal:2',
        'interes_pagado' => 'decimal:2',
        'iva_pagado' => 'decimal:2',
        'monto_total' => 'decimal:2',
        'fecha_pago' => 'date',
        'fecha_registro' => 'datetime'
    ];

    public function empeno()
    {
        return $this->belongsTo(Empeno::class, 'id_empeno');
    }

    public function amortizacion()
    {
        return $this->belongsTo(Amortizacio::class, 'id_amortizacion');
    }
}