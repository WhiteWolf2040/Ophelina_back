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
        'id_empresa',        // <--- AGREGAR ESTE CAMPO
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