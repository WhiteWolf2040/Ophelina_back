<?php
// app/Models/Empeno.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // ← Agregar
use Carbon\Carbon;

class Empeno extends Model
{
    use HasFactory, SoftDeletes; // ← Agregar SoftDeletes

    protected $table = 'empeno';
    protected $primaryKey = 'id_empeno';
    public $timestamps = false; // ← Cambiar a false (no tienes created_at/updated_at)

    protected $fillable = [
        'id_empresa',
        'id_cliente',
        'id_prenda',
        'id_aval',
        'id_tasa',
        'folio',
        'fecha_empeno',
        'fecha_vencimiento',
        'fecha_recuperacion',
        'monto_prestado',
        'intereses',
        'iva_porcentaje',
        'estado',
        'dias_gracia',
        'notas',
        'deleted_at' // ← Agregar para SoftDelete
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
        // ✅ Cambiar 'monto' por 'monto_total'
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
        // Si ya tiene monto_total en la base de datos
        if (isset($this->attributes['monto_total']) && $this->attributes['monto_total']) {
            return $this->attributes['monto_total'];
        }
        
        $interes = $this->monto_prestado * ($this->intereses / 100);
        $iva = $interes * ($this->iva_porcentaje / 100);
        return $this->monto_prestado + $interes + $iva;
    }

    // ==================== SCOPES ====================

    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

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

    public function scopePorEmpresa($query, $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    public function scopePorVencer($query, $dias = 3)
    {
        return $query->where('estado', 'activo')
                     ->whereBetween('fecha_vencimiento', [
                         now(),
                         now()->addDays($dias)
                     ]);
    }

    // ==================== MÉTODOS ====================

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
        
        if ($this->fecha_vencimiento < now()) {
            $this->estado = 'vencido';
            $this->save();
        }
        
        return $this;
    }
}