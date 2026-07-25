<?php
// app/Models/TasaInteres.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TasaInteres extends Model
{
    protected $table = 'tasas_interes';
    protected $primaryKey = 'id_tasa';
    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'nombre',
        'porcentaje',
        'porcentaje_moratorio',
        'plazo_dias',
        'activo',
    ];

    protected $casts = [
        'porcentaje' => 'decimal:2',
        'porcentaje_moratorio' => 'decimal:2',
        'plazo_dias' => 'integer',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function empenos()
    {
        return $this->hasMany(Empeno::class, 'id_tasa');
    }
}