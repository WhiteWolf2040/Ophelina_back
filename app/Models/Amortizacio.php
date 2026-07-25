<?php
// app/Models/Amortizacio.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Amortizacio extends Model
{
    protected $table = 'amortizacion';
    protected $primaryKey = 'id_amortizacion';
    public $timestamps = false;

    // Único lugar donde vive el % de mora default de la plataforma.
    // Se usa solo si el empeño no tiene id_tasa asignado o esa tasa no
    // tiene porcentaje_moratorio configurado (columna en tasas_interes).
    public const MORA_DEFAULT_PORCENTAJE = 5.00;

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

    public function empeno()
    {
        return $this->belongsTo(Empeno::class, 'id_empeno', 'id_empeno');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'id_amortizacion', 'id_amortizacion');
    }

    public function scopePorEstado($query, $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopePorEmpeno($query, $id_empeno)
    {
        return $query->where('id_empeno', $id_empeno);
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeVencidos($query)
    {
        return $query->where('estado', 'vencido')
                     ->where('fecha_pago_programado', '<', now());
    }

    public function getEstaPagadoAttribute()
    {
        return $this->estado === 'pagado';
    }

    public function getEstaVencidoAttribute()
    {
        return $this->estado === 'vencido' ||
               ($this->estado === 'pendiente' && $this->fecha_pago_programado < now());
    }

    /**
     * Días de retraso respecto a fecha_pago_programado (0 si no está vencido).
     */
    public function getDiasRetrasoAttribute()
    {
        if (!$this->estaVencido || !$this->fecha_pago_programado) {
            return 0;
        }

        return now()->diffInDays($this->fecha_pago_programado);
    }

    public function getSaldoPendienteAttribute()
    {
        return $this->monto_total - ($this->monto_pagado ?? 0);
    }

    /**
     * Fórmula: saldo_final × (porcentaje_moratorio / 100 / 30) × días_de_atraso
     * La tasa viene de tasas_interes.porcentaje_moratorio (configurable por
     * cada casa de empeño desde el panel de Configuración); si el empeño no
     * tiene tasa asignada, usa MORA_DEFAULT_PORCENTAJE (5%).
     *
     * @param Carbon|null $fechaReferencia Fecha contra la que se calcula el
     *   atraso. Si no se pasa, se usa "ahora" (para cotizar cuánto se debe
     *   hoy). PagoController la pasa explícita porque el cajero puede estar
     *   registrando un pago con fecha distinta a hoy.
     */
    public function calcularMora(?Carbon $fechaReferencia = null): float
    {
        if (!$this->fecha_pago_programado) {
            return 0.0;
        }

        $fechaReferencia = $fechaReferencia ?? now();
        $fechaProgramada = $this->fecha_pago_programado instanceof Carbon
            ? $this->fecha_pago_programado
            : Carbon::parse($this->fecha_pago_programado);

        if ($fechaReferencia->lessThanOrEqualTo($fechaProgramada)) {
            return 0.0;
        }

        $diasAtraso = $fechaProgramada->diffInDays($fechaReferencia);

        $porcentajeMoratorio = optional($this->empeno)->tasa->porcentaje_moratorio
            ?? self::MORA_DEFAULT_PORCENTAJE;

        // Base de cálculo: saldo_final (el saldo real que se sigue actualizando
        // con cada pago, tanto en sucursal como en línea).
        $saldo = (float) $this->saldo_final;

        $mora = $saldo * ((float) $porcentajeMoratorio / 100 / 30) * $diasAtraso;

        return round($mora, 2);
    }

    /**
     * Días de atraso respecto a una fecha de referencia arbitraria
     * (usado por PagoController al registrar un pago con fecha distinta a hoy).
     */
    public function diasAtrasoRespectoA(Carbon $fechaReferencia): int
    {
        if (!$this->fecha_pago_programado) {
            return 0;
        }

        $fechaProgramada = $this->fecha_pago_programado instanceof Carbon
            ? $this->fecha_pago_programado
            : Carbon::parse($this->fecha_pago_programado);

        if ($fechaReferencia->lessThanOrEqualTo($fechaProgramada)) {
            return 0;
        }

        return $fechaProgramada->diffInDays($fechaReferencia);
    }

    public function marcarComoPagado($fecha_pago = null, $monto_pagado = null)
    {
        $this->estado = 'pagado';
        $this->fecha_pago_real = $fecha_pago ?? now();
        $this->monto_pagado = $monto_pagado ?? $this->monto_total;

        return $this->save();
    }

    public function registrarPagoParcial($monto, $fecha_pago = null)
    {
        $this->monto_pagado = ($this->monto_pagado ?? 0) + $monto;
        $this->fecha_pago_real = $fecha_pago ?? now();

        if ($this->monto_pagado >= $this->monto_total) {
            $this->estado = 'pagado';
        }

        return $this->save();
    }

    /**
     * ✅ NUEVO: Prorroga esta cuota (fecha_pago_programado) y, en el mismo
     * paso, sincroniza empeno.fecha_vencimiento. Son columnas separadas
     * (Amortizacio.fecha_pago_programado vs Empeno.fecha_vencimiento) y el
     * front del cliente lee la del empeño, así que si solo se movía una,
     * la fecha visible para el cliente nunca cambiaba.
     *
     * @param int $dias Días a extender (default 30).
     */
    public function prorrogar(int $dias = 30): void
    {
        $fechaBase = $this->fecha_pago_programado instanceof Carbon
            ? $this->fecha_pago_programado
            : Carbon::parse($this->fecha_pago_programado);

        $nuevaFecha = $fechaBase->copy()->addDays($dias);

        $this->fecha_pago_programado = $nuevaFecha;
        $this->estado = 'pendiente';
        $this->save();

        if ($this->empeno) {
            $this->empeno->update([
                'fecha_vencimiento' => $nuevaFecha,
                'estado' => 'activo',
            ]);
        }
    }
}