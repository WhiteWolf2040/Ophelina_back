<?php
// app/Models/Amortizacion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Amortizacio extends Model
{
    /**
     * La tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'amortizacion';

    /**
     * La llave primaria de la tabla.
     *
     * @var string
     */
    protected $primaryKey = 'id_amortizacion';

    /**
     * Indica si el modelo tiene timestamps.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Los atributos que son asignables en masa.
     *
     * @var array
     */
    protected $fillable = [
        'id_empeno',
        'saldo_inicial',
        'saldo_final',
        'numero_pago',
        'fecha_pago_programado',
        'fecha_pago_real',
        'capital',
        'interes',
        'iva_interes',
        'monto_total',
        'monto_pagado',
        'tipo_pago',
        'estado'
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     *
     * @var array
     */
    protected $casts = [
        'saldo_inicial' => 'decimal:2',
        'saldo_final' => 'decimal:2',
        'capital' => 'decimal:2',
        'interes' => 'decimal:2',
        'iva_interes' => 'decimal:2',
        'monto_total' => 'decimal:2',
        'monto_pagado' => 'decimal:2',
        'fecha_pago_programado' => 'date',
        'fecha_pago_real' => 'date'
    ];

    /**
     * Obtiene el empeño asociado a esta amortización.
     */
    public function empeno()
    {
        return $this->belongsTo(Empeno::class, 'id_empeno', 'id_empeno');
    }

    /**
     * Obtiene los pagos asociados a esta amortización.
     */
    public function pagos()
    {
        return $this->hasMany(Pago::class, 'id_amortizacion', 'id_amortizacion');
    }

    /**
     * Scope para filtrar por estado.
     */
    public function scopePorEstado($query, $estado)
    {
        return $query->where('estado', $estado);
    }

    /**
     * Scope para filtrar por empeño.
     */
    public function scopePorEmpeno($query, $id_empeno)
    {
        return $query->where('id_empeno', $id_empeno);
    }

    /**
     * Scope para obtener pagos pendientes.
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    /**
     * Scope para obtener pagos vencidos.
     */
    public function scopeVencidos($query)
    {
        return $query->where('estado', 'vencido')
                     ->where('fecha_pago_programado', '<', now());
    }

    /**
     * Verifica si la amortización está pagada.
     */
    public function getEstaPagadoAttribute()
    {
        return $this->estado === 'pagado';
    }

    /**
     * Verifica si la amortización está vencida.
     */
    public function getEstaVencidoAttribute()
    {
        return $this->estado === 'vencido' || 
               ($this->estado === 'pendiente' && $this->fecha_pago_programado < now());
    }

    /**
     * Calcula los días de retraso si está vencido.
     */
    public function getDiasRetrasoAttribute()
    {
        if (!$this->estaVencido || !$this->fecha_pago_programado) {
            return 0;
        }
        
        return now()->diffInDays($this->fecha_pago_programado);
    }

    /**
     * Obtiene el saldo pendiente de esta cuota.
     */
    public function getSaldoPendienteAttribute()
    {
        return $this->monto_total - ($this->monto_pagado ?? 0);
    }

    /**
     * Marca la amortización como pagada.
     */
    public function marcarComoPagado($fecha_pago = null, $monto_pagado = null)
    {
        $this->estado = 'pagado';
        $this->fecha_pago_real = $fecha_pago ?? now();
        $this->monto_pagado = $monto_pagado ?? $this->monto_total;
        
        return $this->save();
    }

    /**
     * Registra un pago parcial en esta amortización.
     */
    public function registrarPagoParcial($monto, $fecha_pago = null)
    {
        $this->monto_pagado = ($this->monto_pagado ?? 0) + $monto;
        $this->fecha_pago_real = $fecha_pago ?? now();
        
        // Si ya se pagó el total, cambiar estado
        if ($this->monto_pagado >= $this->monto_total) {
            $this->estado = 'pagado';
        }
        
        return $this->save();
    }
}