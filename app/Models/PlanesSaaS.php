<?php
// app/Models/PlanesSaaS.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanesSaaS extends Model
{
    protected $table = 'planes_saas';
    protected $primaryKey = 'id_plan';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'clave',
        'precio_mensual',
        'max_empleados',
        'max_clientes',
        'max_prendas',
        'max_empenos_activos',
        'dias_prueba',
        'activo'
    ];

    protected $casts = [
        'precio_mensual' => 'decimal:2',
        'max_empleados' => 'integer',
        'max_clientes' => 'integer',
        'max_prendas' => 'integer',
        'max_empenos_activos' => 'integer',
        'dias_prueba' => 'integer',
        'activo' => 'boolean'
    ];

    // Relación con empresas
    public function empresas()
    {
        return $this->hasMany(Empresa::class, 'id_plan');
    }
}