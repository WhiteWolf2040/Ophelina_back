<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected $fillable = ['id_empresa', 'nombre', 'key_hash', 'activo', 'ultimo_uso'];

    protected $casts = ['activo' => 'boolean', 'ultimo_uso' => 'datetime'];

    protected $hidden = ['key_hash'];
}