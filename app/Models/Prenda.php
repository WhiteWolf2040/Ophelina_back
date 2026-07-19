<?php
// app/Models/Prenda.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prenda extends Model
{
    use SoftDeletes;

    protected $table = 'prendas';
    protected $primaryKey = 'id_prenda';
    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'descripcion',
        'tipo',
        'material',
        'peso_gramos',
        'valor_estimado',
        'estado',
        'quitas',
        'fecha_registro',
        'codigo_barras',
        'imagen_url',
        'deleted_at'
    ];

    protected $casts = [
        'peso_gramos' => 'decimal:2',
        'valor_estimado' => 'decimal:2',
        'fecha_registro' => 'datetime'
    ];

    // Relaciones
    public function empenos()
    {
        return $this->hasMany(Empeno::class, 'id_prenda');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function producto_tienda()
    {
        return $this->hasOne(ProductoTienda::class, 'id_prenda');
    }
}