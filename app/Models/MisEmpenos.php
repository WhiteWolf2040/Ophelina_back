<?php
// app/Models/MisEmpenos.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id_empeno
 * @property int $id_empresa
 * @property int $id_cliente
 * @property int $id_prenda
 * @property int|null $id_aval
 * @property int|null $id_tasa
 * @property string $fecha_empeno
 * @property float $monto_prestado
 * @property float $intereses
 * @property float|null $iva_porcentaje
 * @property string|null $fecha_vencimiento
 * @property string $estado
 * @property string|null $folio
 *
 * @property-read Cliente $cliente
 * @property-read Prenda $prenda
 * @property-read Aval|null $aval
 * @property-read TasaInteres|null $tasa
 * @property-read Empresa $empresa
 * @property-read \Illuminate\Database\Eloquent\Collection|Pago[] $pagos
 * @property-read \Illuminate\Database\Eloquent\Collection|Amortizacio[] $amortizaciones
 *
 * @property-read array $saldo_pendiente
 * @property-read bool $pagado_completo
 * @property-read bool $en_tienda
 * @property-read array $abonos_formateados
 * @property-read float $total_pagar
 * @property-read bool $proximo_a_vencer
 * @property-read string $estado_frontend
 */
class MisEmpenos extends Model
{
    protected $table = 'empeno';
    protected $primaryKey = 'id_empeno';
    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'id_cliente',
        'id_prenda',
        'id_aval',
        'id_tasa',
        'fecha_empeno',
        'monto_prestado',
        'intereses',
        'iva_porcentaje',
        'fecha_vencimiento',
        'estado',
        'folio'
    ];

    protected $casts = [
        'monto_prestado' => 'decimal:2',
        'intereses' => 'decimal:2',
        'fecha_empeno' => 'date',
        'fecha_vencimiento' => 'date'
    ];

    // ========== RELACIONES ==========
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function prenda(): BelongsTo
    {
        return $this->belongsTo(Prenda::class, 'id_prenda');
    }

    public function aval(): BelongsTo
    {
        return $this->belongsTo(Aval::class, 'id_aval');
    }

    public function tasa(): BelongsTo
    {
        return $this->belongsTo(TasaInteres::class, 'id_tasa');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'id_empeno');
    }

    public function amortizaciones(): HasMany
    {
        return $this->hasMany(Amortizacio::class, 'id_empeno');
    }

    // ========== ACCESORS PARA CLIENTE ==========

    /**
     * Calcula el saldo pendiente del empeño
     */
    public function getSaldoPendienteAttribute(): array
    {
        $totalPagado = $this->pagos()->sum('monto_total') ?? 0;
        $totalInteresPagado = $this->pagos()->sum('interes_pagado') ?? 0;

        return [
            'saldo_restante' => max(0, $this->monto_prestado - $totalPagado),
            'total_pagado' => $totalPagado,
            'intereses_pagados' => $totalInteresPagado,
            'total_abonado' => $totalPagado + $totalInteresPagado
        ];
    }

    /**
     * Verifica si el empeño está pagado completamente
     */
    public function getPagadoCompletoAttribute(): bool
    {
        return $this->saldo_pendiente['saldo_restante'] <= 0;
    }

    /**
     * Verifica si el empeño está en tienda (vencido y no liquidado)
     */
    public function getEnTiendaAttribute(): bool
    {
        return $this->estado === 'vencido' && $this->fecha_vencimiento < now();
    }

    /**
     * Obtiene los abonos formateados para el frontend
     */
    public function getAbonosFormateadosAttribute(): array
    {
        return $this->pagos->map(function (Pago $pago) {
            return [
                'fecha' => $pago->fecha_pago ? date('d/m/Y', strtotime($pago->fecha_pago)) : 'N/A',
                'monto' => '$' . number_format($pago->monto_total, 2),
                'interesesPagados' => '$' . number_format($pago->interes_pagado ?? 0, 2),
                'montoNumerico' => floatval($pago->monto_total)
            ];
        })->toArray();
    }

    /**
     * Obtiene el total a pagar (monto + intereses)
     */
    public function getTotalPagarAttribute(): float
    {
        return $this->monto_prestado + ($this->intereses ?? 0);
    }

    /**
     * Verifica si está próximo a vencer (7 días o menos)
     */
    public function getProximoAVencerAttribute(): bool
    {
        if (!$this->fecha_vencimiento || $this->estado !== 'activo') {
            return false;
        }

        $dias = now()->diffInDays($this->fecha_vencimiento, false);
        return $dias <= 7 && $dias > 0;
    }

    /**
     * Obtiene el estado del empeño para el frontend (texto)
     */
    public function getEstadoFrontendAttribute(): string
    {
        if ($this->pagado_completo) {
            return 'PAGADO';
        }
        if ($this->en_tienda) {
            return 'EN TIENDA';
        }
        if ($this->estado === 'vencido' || $this->fecha_vencimiento < now()) {
            return 'VENCIDO';
        }
        if ($this->proximo_a_vencer) {
            return 'PROXIMO A VENCER';
        }
        return 'ACTIVO';
    }
}