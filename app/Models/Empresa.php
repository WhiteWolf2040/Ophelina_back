<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    protected $table = 'empresa';
    protected $primaryKey = 'id_empresa';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'nombre_comercial',
        'rfc',
        'telefono',
        'email',
        'direccion',
        'ciudad',
        'estado',
        'codigo_postal',
        'logo_url',
        'precio_oro_gramo',
        'precio_oro_onza',
        'ultima_actualizacion_oro',
        'activo',
        'id_plan',
        'fecha_registro',
        'fecha_inicio_plan',
        'fecha_fin_plan',
        'plan_activo'
    ];

    protected $casts = [
        'activo' => 'boolean',
        'plan_activo' => 'boolean',
        'fecha_registro' => 'datetime',
        'fecha_inicio_plan' => 'date',
        'fecha_fin_plan' => 'date',
        'precio_oro_gramo' => 'decimal:2',
        'precio_oro_onza' => 'decimal:2'
    ];

    // Relación con usuarios
    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'id_empresa');
    }

    // Relación con el plan SaaS
    public function plan()
    {
        return $this->belongsTo(PlanesSaaS::class, 'id_plan');
    }

    // Verificar si la suscripción está activa
    public function tieneSuscripcionActiva()
    {
        if (!$this->plan_activo) {
            return false;
        }
        
        if (!$this->fecha_fin_plan) {
            return false;
        }
        
        return $this->fecha_fin_plan >= now();
    }

    // Obtener días restantes de suscripción
    public function getDiasRestantes()
    {
        if (!$this->fecha_fin_plan || !$this->plan_activo) {
            return 0;
        }
        
        $hoy = now();
        if ($this->fecha_fin_plan < $hoy) {
            return 0;
        }
        
        return $hoy->diffInDays($this->fecha_fin_plan);
    }

    // Verificar si tiene acceso a un módulo según su plan
    public function tieneAccesoModulo($modulo)
    {
        $modulosPorPlan = [
            1 => ['home', 'clientes', 'empenos'],
            3 => ['home', 'clientes', 'pagos', 'empenos', 'configuracion'],
            4 => ['home', 'clientes', 'pagos', 'empenos', 'tienda', 'reportes', 'roles', 'permisos', 'configuracion']
        ];
        
        $planId = $this->id_plan ?? 1;
        $modulosPermitidos = $modulosPorPlan[$planId] ?? $modulosPorPlan[1];
        
        return in_array($modulo, $modulosPermitidos);
    }
}