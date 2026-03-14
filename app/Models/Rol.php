<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    protected $table = 'rol';
    protected $primaryKey = 'id_rol';

    public $timestamps = false;

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'id_rol');
    }
}