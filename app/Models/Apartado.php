<?php
// app/Models/Apartado.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Apartado extends Model
{
    protected $table = 'apartados';
    protected $primaryKey = 'id_apartado';
    public $timestamps = false;

    protected $fillable = [
        'id_cliente',
        'id_producto',
        'fecha_apartado',
        'fecha_expiracion',
        'estado',
        'notas',
        'monto_anticipo',
        'stripe_session_id',
        'stripe_payment_status'
    ];

    protected $casts = [
        'fecha_apartado' => 'datetime',
        'fecha_expiracion' => 'date',
        'monto_anticipo' => 'decimal:2',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function producto()
    {
        return $this->belongsTo(ProductoTienda::class, 'id_producto');
    }
}
