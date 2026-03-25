<?php
// app/Models/TasaInteres.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TasaInteres extends Model
{
    /**
     * La tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'tasas_interes';

    /**
     * La llave primaria de la tabla.
     *
     * @var string
     */
    protected $primaryKey = 'id_tasa';

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
        'nombre',
        'porcentaje',
        'plazo_dias',
        'activo'
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     *
     * @var array
     */
    protected $casts = [
        'porcentaje' => 'decimal:2',
        'plazo_dias' => 'integer',
        'activo' => 'boolean'
    ];

    /**
     * Obtiene los empeños que usan esta tasa de interés.
     */
    public function empenos()
    {
        return $this->hasMany(Empeno::class, 'id_tasa', 'id_tasa');
    }

    /**
     * Scope para obtener solo tasas activas.
     */
    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Scope para buscar por nombre.
     */
    public function scopePorNombre($query, $nombre)
    {
        return $query->where('nombre', 'LIKE', "%{$nombre}%");
    }

    /**
     * Calcula el interés para un monto específico.
     *
     * @param float $monto
     * @return float
     */
    public function calcularInteres($monto)
    {
        return $monto * ($this->porcentaje / 100);
    }

    /**
     * Calcula el monto total a pagar (capital + interés).
     *
     * @param float $monto
     * @return float
     */
    public function calcularMontoTotal($monto)
    {
        return $monto + $this->calcularInteres($monto);
    }

    /**
     * Calcula el interés diario.
     *
     * @param float $monto
     * @return float
     */
    public function calcularInteresDiario($monto)
    {
        if (!$this->plazo_dias) {
            return 0;
        }
        return $this->calcularInteres($monto) / $this->plazo_dias;
    }

    /**
     * Obtiene el porcentaje formateado.
     */
    public function getPorcentajeFormateadoAttribute()
    {
        return number_format($this->porcentaje, 2) . '%';
    }

    /**
     * Obtiene el plazo formateado.
     */
    public function getPlazoFormateadoAttribute()
    {
        if ($this->plazo_dias === 30) {
            return 'Mensual';
        } elseif ($this->plazo_dias === 15) {
            return 'Quincenal';
        } elseif ($this->plazo_dias === 7) {
            return 'Semanal';
        } elseif ($this->plazo_dias === 1) {
            return 'Diario';
        } else {
            return $this->plazo_dias . ' días';
        }
    }

    /**
     * Obtiene el estado como texto.
     */
    public function getEstadoTextoAttribute()
    {
        return $this->activo ? 'Activa' : 'Inactiva';
    }

    /**
     * Obtiene el badge de estado para frontend.
     */
    public function getEstadoBadgeAttribute()
    {
        return $this->activo 
            ? '<span class="badge-success">Activa</span>'
            : '<span class="badge-danger">Inactiva</span>';
    }

    /**
     * Obtiene la descripción completa de la tasa.
     */
    public function getDescripcionCompletaAttribute()
    {
        return "{$this->nombre} - {$this->porcentajeFormateado} / {$this->plazoFormateado}";
    }

    /**
     * Verifica si la tasa puede ser eliminada (no tiene empeños asociados).
     */
    public function getPuedeEliminarseAttribute()
    {
        return $this->empenos()->count() === 0;
    }

    /**
     * Obtiene la cantidad de empeños que usan esta tasa.
     */
    public function getTotalEmpenosAttribute()
    {
        return $this->empenos()->count();
    }
}