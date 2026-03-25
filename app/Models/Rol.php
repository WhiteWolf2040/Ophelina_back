<?php
// app/Models/Rol.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    protected $table = 'rol';
    protected $primaryKey = 'id_rol';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'descripcion',
        'nivel'
    ];

    // Relación con usuarios
    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'id_rol');
    }

    // Relación con permisos (muchos a muchos)
    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, 'rol_permiso', 'id_rol', 'id_permiso')
                    ->withPivot('permitido')
                    ->wherePivot('permitido', 1);
    }

    // Scope para filtrar por nivel
    public function scopePorNivel($query, $nivel)
    {
        return $query->where('nivel', $nivel);
    }

    // Scope para filtrar por nombre
    public function scopePorNombre($query, $nombre)
    {
        return $query->where('nombre', 'LIKE', "%{$nombre}%");
    }

    // Obtener el nivel como texto
    public function getNivelTextoAttribute()
    {
        $niveles = [
            1 => 'Dueño',
            2 => 'Administrador',
            3 => 'Ejecutivo',
            4 => 'Caja'
        ];
        return $niveles[$this->nivel] ?? 'Nivel ' . $this->nivel;
    }
}