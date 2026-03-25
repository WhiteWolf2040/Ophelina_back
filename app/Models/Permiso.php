<?php
// app/Models/Permiso.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    protected $table = 'permisos';
    protected $primaryKey = 'id_permiso';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'descripcion',
        'modulo',
        'Estado'
    ];

    // Relación con roles (muchos a muchos)
    public function roles()
    {
        return $this->belongsToMany(Rol::class, 'rol_permiso', 'id_permiso', 'id_rol')
                    ->withPivot('permitido');
    }

    // Scope para filtrar por módulo
    public function scopePorModulo($query, $modulo)
    {
        return $query->where('modulo', $modulo);
    }

    // Scope para filtrar por nombre
    public function scopePorNombre($query, $nombre)
    {
        return $query->where('nombre', 'LIKE', "%{$nombre}%");
    }

    
}