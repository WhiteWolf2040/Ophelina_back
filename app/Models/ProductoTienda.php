<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoTienda extends Model
{
    protected $table = 'producto_tienda';
    protected $primaryKey = 'id_producto';
    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'id_prenda',
        'nombre',
        'descripcion',
        'precio',
        'descuento',
        'stock',
        'estado_producto',
        'visible',
        'destacado',
        'imagen_url',
        'fecha_publicacion',
    ];

    public function prenda()
    {
        return $this->belongsTo(Prenda::class, 'id_prenda', 'id_prenda');
    }

    public function apartados()
    {
        return $this->hasMany(Apartado::class, 'id_producto', 'id_producto');
    }
}