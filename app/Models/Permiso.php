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
        'id_empresa',  // ✅ Agregar este campo
        'nombre',
        'descripcion',
        'modulo',
        'Estado'
    ];

    // Relación con roles (muchos a muchos)
    public function roles()
    {
        return $this->belongsToMany(
            Rol::class, 
            'rol_permiso', 
            'id_permiso',  // Foreign key en la tabla pivote
            'id_rol'       // Related key en la tabla pivote
        )->withPivot('permitido', 'id_empresa');  // Agregar id_empresa al pivot
    }

    // Relación con empresa
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
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

    // Scope para filtrar por empresa
    public function scopePorEmpresa($query, $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }
}