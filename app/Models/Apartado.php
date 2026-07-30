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
        'stripe_payment_status',
        'codigo_entrega',
        'entregado',
        'fecha_entrega',
        'id_usuario_entrego',
    ];

    protected $casts = [
        'fecha_apartado' => 'datetime',
        'fecha_expiracion' => 'date',
        'monto_anticipo' => 'decimal:2',
        'entregado' => 'boolean',
        'fecha_entrega' => 'datetime',
    ];

    // NUEVO: garantiza que TODO apartado tenga código de entrega,
    // sin importar qué controlador lo cree (OpheliaTiendaController,
    // ApartadoController, etc). Así se elimina la dependencia de que
    // cada controlador recuerde generarlo manualmente.
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Apartado $apartado) {
            if (empty($apartado->codigo_entrega)) {
                $apartado->codigo_entrega = self::generarCodigoUnico();
            }
        });
    }

    private static function generarCodigoUnico(): string
    {
        $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0,O,1,I para evitar confusión
        do {
            $codigo = '';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= $caracteres[random_int(0, strlen($caracteres) - 1)];
            }
        } while (self::where('codigo_entrega', $codigo)->exists());

        return $codigo;
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function producto()
    {
        return $this->belongsTo(ProductoTienda::class, 'id_producto');
    }
}