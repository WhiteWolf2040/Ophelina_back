<?php
// app/Models/ImagenPrenda.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImagenPrenda extends Model
{
    protected $table = 'imagen_prenda';
    protected $primaryKey = 'id_imagen';
    public $timestamps = false;

    protected $fillable = [
       'id_prenda',
        'ruta_archivo',
        'imagen_data',      //  Para guardar la imagen en BD
        'imagen_mime',
        'cloudinary_url',   //  Para compatibilidad (opcional)
        'es_principal',
        'orden',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
        'orden' => 'integer',
    ];

    public function prenda()
    {
        return $this->belongsTo(Prenda::class, 'id_prenda', 'id_prenda');
    }
}