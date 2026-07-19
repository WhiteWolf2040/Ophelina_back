<?php
// app/Models/ProductoTienda.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductoTienda extends Model
{
    use SoftDeletes;

    protected $table = 'producto_tienda';
    protected $primaryKey = 'id_producto';
    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'id_prenda',
        'nombre',
        'categoria',           // ← NUEVO
        'descripcion',
        'precio',
        'descuento',
        'stock',
        'estado_producto',
        'visible',
        'destacado',
        'imagen_url',
        'fecha_publicacion',
        'deleted_at',
        'publicacion_automatica',      // ← NUEVO
        'fecha_vencimiento_contrato',  // ← NUEVO
        'id_empeno_original'           // ← NUEVO
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'descuento' => 'integer',
        'stock' => 'integer',
        'visible' => 'boolean',
        'destacado' => 'boolean',
        'fecha_publicacion' => 'date',
        'publicacion_automatica' => 'boolean'
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function prenda()
    {
        return $this->belongsTo(Prenda::class, 'id_prenda');
    }

    public function empeno()
    {
        return $this->belongsTo(Empeno::class, 'id_empeno_original');
    }

    public function scopeVisible($query)
    {
        return $query->where('visible', true);
    }

    public function scopeDisponible($query)
    {
        return $query->where('stock', '>', 0);
    }

    public function getEstadoAttribute()
    {
        return $this->estado_producto;
    }
}