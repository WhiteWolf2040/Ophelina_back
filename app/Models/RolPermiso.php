<?php
// app/Models/RolPermiso.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolPermiso extends Model
{
    protected $table = 'rol_permiso';
    protected $primaryKey = 'id_rol_permiso';
    public $timestamps = false;

    protected $fillable = [
        'id_rol',
        'id_permiso',
        'permitido'
    ];

    protected $casts = [
        'permitido' => 'boolean'
    ];

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'id_rol');
    }

    public function permiso()
    {
        return $this->belongsTo(Permiso::class, 'id_permiso');
    }
}