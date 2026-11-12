<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $table = 'sucursales';
    protected $primaryKey = 'id_sucursal';
    public $timestamps = false;

    protected $fillable = ['id_empresa','nombre','encargado','telefono','direccion','activa'];
    protected $casts = ['activa' => 'boolean', 'es_matriz' => 'boolean'];
}