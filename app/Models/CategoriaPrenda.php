<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaPrenda extends Model
{
    protected $table = 'categorias_prenda';
    protected $primaryKey = 'id_categoria';
    public $timestamps = false;

    protected $guarded = ['id_categoria'];

    protected $casts = [
        'avaluo_pct' => 'float',
        'activa'     => 'boolean',
    ];
}