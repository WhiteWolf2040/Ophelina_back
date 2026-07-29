<?php
// app/Models/Empeno.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Empeno extends Model
{
    use HasFactory, SoftDeletes;

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
        'fecha_recuperacion',
        'dias_gracia',
        'notas',
        'estado',
        'folio',
        'deleted_at'
    ];

    protected $casts = [
        'monto_prestado' => 'decimal:2',
        'intereses' => 'decimal:2',
        'iva_porcentaje' => 'decimal:2',
        'dias_gracia' => 'integer',
        'fecha_empeno' => 'date',
        'fecha_vencimiento' => 'date',
        'fecha_recuperacion' => 'date',
        'deleted_at' => 'datetime'
    ];

    // ============================================
    // ACCESSORS
    // ============================================

    /**
     * Obtener el estado real del empeño (calculado en tiempo real)
     * Compara solo por día (Y-m-d) para evitar problemas de timezone/hora
     */
    public function getEstadoRealAttribute()
    {
        if (in_array($this->estado, ['en_tienda', 'pagado', 'cancelado'])) {
            return $this->estado;
        }

        if ($this->estado === 'activo' && $this->fecha_vencimiento->format('Y-m-d') < now()->format('Y-m-d')) {
            return 'vencido';
        }

        return $this->estado;
    }

    /**
     * Obtener los días vencidos (comparación por día)
     */
    public function getDiasVencidosAttribute()
    {
        if (!$this->fecha_vencimiento) {
            return 0;
        }

        if ($this->fecha_vencimiento->format('Y-m-d') < now()->format('Y-m-d')) {
            return max(0, now()->startOfDay()->diffInDays($this->fecha_vencimiento->startOfDay(), false) * -1);
        }

        return 0;
    }

    /**
     * Obtener los días de retraso (alias más simple, usado por tu compañera)
     */
    public function getDiasRetrasoAttribute()
    {
        if (!$this->esta_vencido) return 0;
        return now()->startOfDay()->diffInDays($this->fecha_vencimiento->startOfDay());
    }

    /**
     * Obtener el estado para mostrar (formateado)
     */
    public function getEstadoTextoAttribute()
    {
        $estadoReal = $this->estado_real;

        return match($estadoReal) {
            'activo' => 'Activo',
            'vencido' => 'Vencido',
            'pagado' => 'Pagado',
            'prorrogado' => 'Prorrogado',
            'cancelado' => 'Cancelado',
            default => ucfirst($estadoReal)
        };
    }

    /**
     * Obtener el color del estado para el frontend
     */
    public function getEstadoColorAttribute()
    {
        $estadoReal = $this->estado_real;

        return match($estadoReal) {
            'activo' => 'success',
            'vencido' => 'danger',
            'pagado' => 'info',
            'prorrogado' => 'warning',
            'cancelado' => 'secondary',
            default => 'secondary'
        };
    }

    /**
     * Calcula el saldo pendiente del empeño
     */
    public function getSaldoPendienteAttribute()
    {
        $totalPagado = $this->pagos()->sum('monto_total') ?? 0;
        return max(0, ($this->monto_prestado ?? 0) - $totalPagado);
    }

    /**
     * Verifica si el empeño está activo
     */
    public function getEstaActivoAttribute()
    {
        return $this->estado === 'activo';
    }

    /**
     * Verifica si el empeño está vencido (comparación por día)
     */
    public function getEstaVencidoAttribute()
    {
        return $this->estado === 'vencido' ||
               ($this->estado === 'activo' && $this->fecha_vencimiento->format('Y-m-d') < now()->format('Y-m-d'));
    }

    /**
     * Obtiene el monto total con intereses (de tu compañera)
     */
            public function getMontoTotalAttribute()
            {
                if (isset($this->attributes['monto_total']) && $this->attributes['monto_total']) {
                    return $this->attributes['monto_total'];
                }

                $interes = (float) ($this->intereses ?? 0); // ya es monto en pesos, no %
                $iva = $interes * (((float) ($this->iva_porcentaje ?? 16)) / 100);

                return $this->monto_prestado + $interes + $iva;
            }

    // ============================================
    // SCOPES (comparación por día, consistente con el resto)
    // ============================================

    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopeVencidos($query)
    {
        $hoy = now()->toDateString();

        return $query->where(function($q) use ($hoy) {
            $q->where('estado', 'vencido')
              ->orWhere(function($sub) use ($hoy) {
                  $sub->where('estado', 'activo')
                      ->whereDate('fecha_vencimiento', '<', $hoy);
              });
        });
    }

    public function scopeActivosReales($query)
    {
        return $query->where('estado', 'activo')
            ->whereDate('fecha_vencimiento', '>=', now()->toDateString());
    }

    public function scopePorEmpresa($query, $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    public function scopePorVencer($query, $dias = 3)
    {
        return $query->where('estado', 'activo')
                     ->whereBetween('fecha_vencimiento', [
                         now()->toDateString(),
                         now()->addDays($dias)->toDateString()
                     ]);
    }

    // ============================================
    // RELACIONES
    // ============================================

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function prenda()
    {
        return $this->belongsTo(Prenda::class, 'id_prenda');
    }

    public function aval()
    {
        return $this->belongsTo(Aval::class, 'id_aval');
    }

    public function tasa()
    {
        return $this->belongsTo(TasaInteres::class, 'id_tasa');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'id_empeno');
    }

    public function amortizaciones()
    {
        return $this->hasMany(Amortizacio::class, 'id_empeno');
    }

    /**
     * Producto de tienda asociado (para publicación automática) - de tu compañera
     */
    public function producto()
    {
        return $this->hasOne(ProductoTienda::class, 'id_prenda', 'id_prenda')
                    ->where('id_empresa', $this->id_empresa);
    }

    // ============================================
    // MÉTODOS (de tu compañera)
    // ============================================

    public function marcarComoRecuperado()
    {
        $this->estado = 'recuperado';
        $this->fecha_recuperacion = now();
        $this->save();

        if ($this->prenda) {
            $this->prenda->estado = 'Disponible';
            $this->prenda->save();
        }

        return $this;
    }

    public function marcarComoVencido()
    {
        $this->estado = 'vencido';
        $this->save();
        return $this;
    }

    public function renovar($dias = 30)
    {
        $nuevaFecha = Carbon::parse($this->fecha_vencimiento)->addDays($dias);
        $this->fecha_vencimiento = $nuevaFecha;
        $this->estado = 'activo';
        $this->save();
        return $this;
    }

    public function actualizarEstado()
    {
        if ($this->estado !== 'activo') return $this;

        if ($this->fecha_vencimiento->format('Y-m-d') < now()->format('Y-m-d')) {
            $this->estado = 'vencido';
            $this->save();
        }

        return $this;
    }
}