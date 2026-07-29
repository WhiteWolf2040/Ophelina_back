<?php
// app/Models/MisEmpenos.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MisEmpenos extends Model
{
    protected $table = 'empeno';
    protected $primaryKey = 'id_empeno';
    public $timestamps = false;

    protected $fillable = [
        'id_empresa', 'id_cliente', 'id_prenda', 'id_aval', 'id_tasa',
        'fecha_empeno', 'monto_prestado', 'intereses', 'iva_porcentaje',
        'fecha_vencimiento', 'estado', 'folio'
    ];

    protected $casts = [
        'monto_prestado' => 'decimal:2',
        'intereses' => 'decimal:2',
        'fecha_empeno' => 'date',
        'fecha_vencimiento' => 'date'
    ];

    private const MORA_DEFAULT_PORCENTAJE = 5.00;

    // ✅ Caché en memoria: el saldo se calcula UNA vez por instancia,
    // aunque se le llame varias veces en cascada (pagado_completo,
    // estado_frontend, mora, etc. lo invocan internamente).
    private ?array $saldoPendienteCache = null;

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'id_cliente'); }
    public function prenda(): BelongsTo { return $this->belongsTo(Prenda::class, 'id_prenda'); }
    public function aval(): BelongsTo { return $this->belongsTo(Aval::class, 'id_aval'); }
    public function tasa(): BelongsTo { return $this->belongsTo(TasaInteres::class, 'id_tasa'); }
    public function empresa(): BelongsTo { return $this->belongsTo(Empresa::class, 'id_empresa'); }
    public function pagos(): HasMany { return $this->hasMany(Pago::class, 'id_empeno'); }
    public function amortizaciones(): HasMany { return $this->hasMany(Amortizacio::class, 'id_empeno'); }

    /**
     * ✅ Usa la relación 'pagos' ya cargada por el controller (with(['pagos']))
     * en vez de lanzar queries nuevas. 'saldo_restante' resta solo el
     * CAPITAL pagado (no el monto_total, que incluye interés/iva), para
     * reflejar cuánto capital le queda pendiente al cliente.
     */
    public function getSaldoPendienteAttribute(): array
    {
        if ($this->saldoPendienteCache !== null) {
            return $this->saldoPendienteCache;
        }

        $pagosCollection = $this->relationLoaded('pagos')
            ? $this->pagos
            : $this->pagos()->get();

        $capitalPagado = (float) $pagosCollection->sum('capital_pagado');
        $interesPagado = (float) $pagosCollection->sum('interes_pagado');
        $totalPagado   = (float) $pagosCollection->sum('monto_total');

        return $this->saldoPendienteCache = [
            'saldo_restante'    => max(0, (float) $this->monto_prestado - $capitalPagado),
            'total_pagado'      => $totalPagado,
            'intereses_pagados' => $interesPagado,
            'total_abonado'     => $totalPagado, // ya no se duplica el interés
        ];
    }

    public function getPagadoCompletoAttribute(): bool
    {
        return $this->saldo_pendiente['saldo_restante'] <= 0;
    }

    public function getEnTiendaAttribute(): bool
    {
        return $this->estado === 'en_tienda';
    }

    public function getAbonosFormateadosAttribute(): array
    {
        $pagos = $this->relationLoaded('pagos') ? $this->pagos : $this->pagos()->get();

        return $pagos->map(function (Pago $pago) {
            return [
                'fecha' => $pago->fecha_pago ? date('d/m/Y', strtotime($pago->fecha_pago)) : 'N/A',
                'monto' => '$' . number_format($pago->monto_total, 2),
                'interesesPagados' => '$' . number_format($pago->interes_pagado ?? 0, 2),
                'montoNumerico' => floatval($pago->monto_total)
            ];
        })->toArray();
    }

    /**
     * ✅ Ahora incluye IVA (antes solo sumaba capital + interés) y asume
     * que 'intereses' ya está en pesos (tras el fix de migración de datos),
     * no en porcentaje crudo.
     */
    public function getTotalPagarAttribute(): float
    {
        $interes = (float) ($this->intereses ?? 0);
        $iva = $interes * (((float) ($this->iva_porcentaje ?? 16)) / 100);

        return round((float) $this->monto_prestado + $interes + $iva, 2);
    }

    public function getDiasRetrasoAttribute(): int
    {
        if (!$this->fecha_vencimiento || $this->pagado_completo) return 0;
        if (in_array($this->estado, ['pagado', 'cancelado'])) return 0;

        $hoy = now()->startOfDay();
        $vencimiento = $this->fecha_vencimiento->copy()->startOfDay();

        if ($vencimiento >= $hoy) return 0;

        return $vencimiento->diffInDays($hoy);
    }

    public function getMoraAttribute(): float
    {
        $dias = $this->dias_retraso;
        if ($dias <= 0) return 0.0;

        $porcentajeMoratorio = optional($this->tasa)->porcentaje_moratorio
            ?? self::MORA_DEFAULT_PORCENTAJE;

        $saldoRestante = $this->saldo_pendiente['saldo_restante'];
        $mora = $saldoRestante * ((float) $porcentajeMoratorio / 100 / 30) * $dias;

        return round($mora, 2);
    }

    public function getTotalPagarConMoraAttribute(): float
    {
        return round($this->total_pagar + $this->mora, 2);
    }

    public function getProximoAVencerAttribute(): bool
    {
        if (!$this->fecha_vencimiento || $this->estado !== 'activo') return false;

        $dias = now()->diffInDays($this->fecha_vencimiento, false);
        return $dias <= 7 && $dias > 0;
    }

    public function getEstadoFrontendAttribute(): string
    {
        if ($this->pagado_completo) return 'PAGADO';
        if ($this->en_tienda) return 'EN TIENDA';
        if ($this->estado === 'vencido' || $this->fecha_vencimiento < now()) return 'VENCIDO';
        if ($this->proximo_a_vencer) return 'PROXIMO A VENCER';
        return 'ACTIVO';
    }
}