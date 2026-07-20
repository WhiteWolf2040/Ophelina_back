<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empeno extends Model
{
    protected $table = 'empeno';
    protected $primaryKey = 'id_empeno';
    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'id_cliente',
        'id_prenda',
        'id_aval',
        'id_tasa',
        'fecha_empeno',
        'monto_prestado',
        'intereses',
        'iva_porcentaje',
        'fecha_vencimiento',
        'estado',
        'folio',
    ];

    // Relaciones
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function prenda()
    {
        return $this->belongsTo(Prenda::class, 'id_prenda', 'id_prenda');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'id_empeno', 'id_empeno');
    }
}