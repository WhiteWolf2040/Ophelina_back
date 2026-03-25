<?php
// app/Models/Aval.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Aval extends Model
{
    /**
     * La tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'aval';

    /**
     * La llave primaria de la tabla.
     *
     * @var string
     */
    protected $primaryKey = 'id_aval';

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
        'apellido',
        'telefono',
        'direccion',
        'email',
        'identificacion'
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     *
     * @var array
     */
    protected $casts = [
        'telefono' => 'string',
    ];

    /**
     * Obtiene el nombre completo del aval.
     */
    public function getNombreCompletoAttribute()
    {
        return trim($this->nombre . ' ' . $this->apellido);
    }

    /**
     * Obtiene los empeños donde este usuario es aval.
     */
    public function empenos()
    {
        return $this->hasMany(Empeno::class, 'id_aval', 'id_aval');
    }

    /**
     * Obtiene los documentos asociados a este aval.
     */
    public function documentos()
    {
        return $this->hasMany(DocumentoAval::class, 'id_aval', 'id_aval');
    }

    /**
     * Scope para buscar por nombre o identificación.
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where(function($q) use ($termino) {
            $q->where('nombre', 'LIKE', "%{$termino}%")
              ->orWhere('apellido', 'LIKE', "%{$termino}%")
              ->orWhere('identificacion', 'LIKE', "%{$termino}%")
              ->orWhere('telefono', 'LIKE', "%{$termino}%")
              ->orWhere('email', 'LIKE', "%{$termino}%");
        });
    }

    /**
     * Scope para filtrar por identificación.
     */
    public function scopePorIdentificacion($query, $identificacion)
    {
        return $query->where('identificacion', $identificacion);
    }

    /**
     * Verifica si el aval tiene empeños activos.
     */
    public function getTieneEmpenosActivosAttribute()
    {
        return $this->empenos()
            ->where('estado', 'activo')
            ->exists();
    }

    /**
     * Obtiene la cantidad de empeños donde ha sido aval.
     */
    public function getTotalEmpenosAttribute()
    {
        return $this->empenos()->count();
    }

    /**
     * Obtiene el monto total garantizado por este aval.
     */
    public function getMontoTotalGarantizadoAttribute()
    {
        return $this->empenos()
            ->whereIn('estado', ['activo', 'vencido'])
            ->sum('monto_prestado');
    }
}