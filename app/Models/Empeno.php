<?php
// app/Models/Empeno.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Empeno extends Model
{
    use HasFactory;

    protected $table = 'empeno';
    protected $primaryKey = 'id_empeno';
    public $timestamps = true; // Cambiar a true si tienes created_at/updated_at

    protected $fillable = [
        'id_empresa',
        'id_cliente',
        'id_prenda',
        'id_aval',
        'id_tasa',
        'folio',
        'fecha_empeno',
        'fecha_inicio',        // Para compatibilidad
        'fecha_vencimiento',
        'fecha_recuperacion',
        'monto_prestado',
        'intereses',
        'interes_generado',    // Para compatibilidad
        'iva_porcentaje',
        'monto_total',
        'estado',              // 'activo', 'pagado', 'vencido', 'recuperado'
        'dias_gracia',
        'notas'
    ];

    protected $casts = [
        'monto_prestado' => 'decimal:2',
        'intereses' => 'decimal:2',
        'interes_generado' => 'decimal:2',
        'monto_total' => 'decimal:2',
        'iva_porcentaje' => 'decimal:2',
        'dias_gracia' => 'integer',
        'fecha_empeno' => 'date',
        'fecha_inicio' => 'date',
        'fecha_vencimiento' => 'date',
        'fecha_recuperacion' => 'date'
    ];

    // ==================== RELACIONES ====================
    
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
        return $this->hasMany(Amortizacion::class, 'id_empeno');
    }

    /**
     * Producto de tienda asociado (para publicación automática)
     */
    public function producto()
    {
        return $this->hasOne(ProductoTienda::class, 'id_prenda', 'id_prenda')
                    ->where('id_empresa', $this->id_empresa);
    }

    // ==================== ACCESORS ====================

    /**
     * Calcula el saldo pendiente total
     */
    public function getSaldoPendienteAttribute()
    {
        $totalPagado = $this->pagos()->sum('monto') ?? 0;
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
     * Verifica si el empeño está vencido
     */
    public function getEstaVencidoAttribute()
    {
        return $this->estado === 'vencido' || 
               ($this->estado === 'activo' && $this->fecha_vencimiento < now());
    }

    /**
     * Obtiene los días de retraso
     */
    public function getDiasRetrasoAttribute()
    {
        if (!$this->esta_vencido) return 0;
        return now()->diffInDays($this->fecha_vencimiento);
    }

    /**
     * Obtiene el monto total con intereses
     */
    public function getMontoTotalAttribute()
    {
        if ($this->attributes['monto_total']) {
            return $this->attributes['monto_total'];
        }
        
        $interes = $this->monto_prestado * ($this->intereses / 100);
        $iva = $interes * ($this->iva_porcentaje / 100);
        return $this->monto_prestado + $interes + $iva;
    }

    /**
     * Obtiene la fecha de inicio (para compatibilidad)
     */
    public function getFechaInicioAttribute()
    {
        return $this->fecha_empeno ?? $this->attributes['fecha_inicio'] ?? null;
    }

    // ==================== SCOPES ====================

    /**
     * Scope para empeños activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    /**
     * Scope para empeños vencidos
     */
    public function scopeVencidos($query)
    {
        return $query->where(function($q) {
            $q->where('estado', 'vencido')
              ->orWhere(function($q2) {
                  $q2->where('estado', 'activo')
                     ->where('fecha_vencimiento', '<', now());
              });
        });
    }

    /**
     * Scope para empeños por empresa
     */
    public function scopePorEmpresa($query, $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    /**
     * Scope para empeños que vencen en X días
     */
    public function scopePorVencer($query, $dias = 3)
    {
        return $query->where('estado', 'activo')
                     ->whereBetween('fecha_vencimiento', [
                         now(),
                         now()->addDays($dias)
                     ]);
    }

    // ==================== MÉTODOS ====================

    /**
     * Marcar como recuperado
     */
    public function marcarComoRecuperado()
    {
        $this->estado = 'recuperado';
        $this->fecha_recuperacion = now();
        $this->save();
        
        // Actualizar estado de la prenda
        if ($this->prenda) {
            $this->prenda->estado = 'Disponible';
            $this->prenda->save();
        }
        
        return $this;
    }

    /**
     * Marcar como vencido
     */
    public function marcarComoVencido()
    {
        $this->estado = 'vencido';
        $this->save();
        return $this;
    }

    /**
     * Renovar empeño (pagar solo intereses)
     */
    public function renovar($dias = 30)
    {
        $nuevaFecha = Carbon::parse($this->fecha_vencimiento)->addDays($dias);
        $this->fecha_vencimiento = $nuevaFecha;
        $this->estado = 'activo';
        $this->save();
        
        return $this;
    }

    /**
     * Verificar y actualizar estado automáticamente
     */
    public function actualizarEstado()
    {
        if ($this->estado !== 'activo') return $this;
        
        if ($this->fecha_vencimiento < now()) {
            $this->estado = 'vencido';
            $this->save();
        }
        
        return $this;
    }
}