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
        'id_empresa',        // <--- AGREGAR ESTE CAMPO
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

    // <--- AGREGAR ESTA RELACIÓN
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    //modelo usuario se agrego:
    //  AGREGAR: para que $user->id_cliente funcione en los controladores
    protected $appends = [
        'id_cliente'
    ];


    //  AGREGAR: relación hacia el registro de cliente correspondiente
    public function cliente()
    {
        return $this->hasOne(Cliente::class, 'id_usuario', 'id_usuario');
    }

    // AGREGAR: accessor para que $user->id_cliente devuelva el id_cliente real
    public function getIdClienteAttribute()
    {
        return $this->cliente()->value('id_cliente');
    }
}