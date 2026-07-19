<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // ← Si usas soft delete

class Prenda extends Model
{
    use SoftDeletes; // ← Opcional

    protected $table = 'prendas';
    protected $primaryKey = 'id_prenda';
    public $timestamps = false;

    // ✅ TODOS los campos de la tabla
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
        'deleted_at' // ← Si usas soft delete
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