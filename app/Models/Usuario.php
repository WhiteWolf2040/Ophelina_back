<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuario';
    protected $primaryKey = 'id_usuario';

    public $timestamps = false;

    protected $fillable = [
        'id_rol',
        'nombre',
        'correo',
        'contrasena',
        'telefono',
        'foto_perfil',
        'activo',
        'fecha_registro',
        'ultimo_acceso'
    ];

    protected $hidden = [
        'contrasena'
    ];

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'id_rol');
    }
}