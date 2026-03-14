<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'apellido',
        'telefono',
        'correo',
        'direccion',
        'fecha_registro'
    ];

    public function empenos()
    {
        return $this->hasMany(Empeno::class,'id_cliente');
    }
}