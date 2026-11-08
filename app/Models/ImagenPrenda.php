<?php
// app/Models/ImagenPrenda.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ImagenPrenda extends Model
{
    protected $table = 'imagen_prenda';
    protected $primaryKey = 'id_imagen';
    public $timestamps = false;

    protected $fillable = [
        'id_prenda',
        'ruta_archivo',
        'imagen_data',
        'imagen_mime',
        'cloudinary_url',
        'es_principal',
        'orden',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
        'orden' => 'integer',
    ];

    // El base64 pesa mucho: nunca debe viajar en las respuestas JSON
    protected $hidden = ['imagen_data'];

    // OJO: ya no usamos $appends. Los controladores leen ->url_publica
    // explícitamente, así no se ejecuta el accesor (y sus consultas a disco)
    // cada vez que un modelo se serializa a JSON.

    public function prenda()
    {
        return $this->belongsTo(Prenda::class, 'id_prenda', 'id_prenda');
    }

    /**
     * URL pública utilizable por un <img>, sin importar cómo se guardó la imagen.
     * Devuelve null si no hay nada válido (el frontend pone el placeholder).
     */
    public function getUrlPublicaAttribute(): ?string
    {
        // 1) Cloudinary o cualquier URL absoluta (se fuerza https)
        $valor = $this->cloudinary_url;
        if (!empty($valor)) {
            if (preg_match('#^https?://#i', $valor)) {
                return preg_replace('#^http://#i', 'https://', $valor);
            }

            // 2) Ruta relativa guardada en el campo equivocado
            $ruta = ltrim(preg_replace('#^/?(storage/)?#', '', $valor), '/');
            if (Storage::disk('public')->exists($ruta)) {
                return url('storage/' . $ruta);
            }
        }

        // 3) Imágenes antiguas en base64 (la ruta /api/imagen-prenda/{id} debe ser pública)
        if (!empty($this->imagen_data)) {
            return url('/api/imagen-prenda/' . $this->id_prenda);
        }

        // 4) Ruta en ruta_archivo, solo si parece ruta y el archivo existe
        if (!empty($this->ruta_archivo)
            && str_contains($this->ruta_archivo, '/')
            && Storage::disk('public')->exists(ltrim($this->ruta_archivo, '/'))) {
            return url('storage/' . ltrim($this->ruta_archivo, '/'));
        }

        return null;
    }
}