<?php
// app/Models/Empeno.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empeno extends Model
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

    // ============================================
    //  NUEVO: Atributos virtuales (Accessors)
    // ============================================
    
    /**
     * Obtener el estado real del empeño (calculado en tiempo real)
     */
    // En el modelo Empeno.php
        public function getEstadoRealAttribute()
        {
            // Si ya está en tienda, pagado o cancelado, mantener ese estado
            if (in_array($this->estado, ['en_tienda', 'pagado', 'cancelado'])) {
                return $this->estado;
            }
            
            // Si está activo y pasó la fecha de vencimiento
            if ($this->estado === 'activo' && $this->fecha_vencimiento < now()) {
                // Podría ser 'vencido' pero la prenda aún está en la casa
                return 'vencido';
            }
            
            return $this->estado;
        }
    
    /**
     * Obtener los días vencidos
     */
    public function getDiasVencidosAttribute()
    {
        if (!$this->fecha_vencimiento) {
            return 0;
        }
        
        if ($this->fecha_vencimiento < now()) {
            return max(0, now()->diffInDays($this->fecha_vencimiento, false));
        }
        
        return 0;
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

    // ============================================
    // 🔥 NUEVO: Scopes para consultas
    // ============================================
    
    /**
     * Scope para empeños vencidos (reales)
     */
    public function scopeVencidos($query)
    {
        return $query->where(function($q) {
            $q->where('estado', 'vencido')
              ->orWhere(function($sub) {
                  $sub->where('estado', 'activo')
                      ->where('fecha_vencimiento', '<', now());
              });
        });
    }
    
    /**
     * Scope para empeños activos reales (no vencidos)
     */
    public function scopeActivosReales($query)
    {
        return $query->where('estado', 'activo')
            ->where('fecha_vencimiento', '>=', now());
    }
    
    /**
     * Scope para empeños activos (incluyendo los que están vencidos pero no actualizados)
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    // ============================================
    // RELACIONES (YA LAS TIENES)
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
     * Calcula el saldo pendiente del empeño.
     */
    public function getSaldoPendienteAttribute()
    {
        $totalPagado = $this->pagos()->sum('monto_total') ?? 0;
        return $this->monto_prestado - $totalPagado;
    }

    /**
     * Verifica si el empeño está activo.
     */
    public function getEstaActivoAttribute()
    {
        return $this->estado === 'activo';
    }

    /**
     * Verifica si el empeño está vencido.
     */
    public function getEstaVencidoAttribute()
    {
        return $this->estado === 'vencido' || 
               ($this->estado === 'activo' && $this->fecha_vencimiento < now());
    }
}