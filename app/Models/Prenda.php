<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prenda extends Model
{

    protected $table = 'prendas';

    protected $primaryKey = 'id_prenda';

    public $timestamps = false;

    protected $fillable = [
        'descripcion',
        'tipo',
        'estado',
        'avaluo',
        'fecha_registro'
    ];

    public function empenos()
    {
        return $this->hasMany(Empeno::class,'id_prenda');
    }

}