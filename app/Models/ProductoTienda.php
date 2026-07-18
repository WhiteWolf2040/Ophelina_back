<?php
// app/Models/ProductoTienda.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoTienda extends Model
{
    protected $table = 'producto_tienda';
    protected $primaryKey = 'id_producto';
    
    // ❌ No uses timestamps (no tienes created_at/updated_at)
    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'id_prenda',
        'nombre',
        'descripcion',
        'precio',
        'descuento',
        'stock',
        'estado_producto',  // ← CAMBIO: estado_producto
        'visible',
        'destacado',
        'imagen_url',
        'fecha_publicacion'
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'descuento' => 'integer',
        'stock' => 'integer',
        'visible' => 'boolean',
        'destacado' => 'boolean',
        'fecha_publicacion' => 'date'
    ];

    // Relaciones
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function prenda()
    {
        return $this->belongsTo(Prenda::class, 'id_prenda');
    }

    // Scopes
    public function scopeVisible($query)
    {
        return $query->where('visible', true);
    }

    public function scopeDisponible($query)
    {
        return $query->where('stock', '>', 0);
    }

    // Accesor
    public function getEstadoAttribute()
    {
        return $this->estado_producto;
    }
}