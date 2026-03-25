<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente';

    public $timestamps = false;

    protected $fillable = [
       'id_usuario',        
        'id_empresa',        
        'nombre',
        'apellido',
        'telefono',
        'correo',
        'direccion',
        'codigo_postal',
        'ciudad',
        'estado',
        'fecha_registro',
        'activo',
        'tipo_identificacion',
        'numero_identificacion',
        'foto_perfil',
        'foto_ine'
    ];
  public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
    
    public function empenos()
    {
        return $this->hasMany(Empeno::class, 'id_cliente');
    }

    // <--- AGREGAR ESTA RELACIÓN
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }
}