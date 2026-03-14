<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{

    protected $table = 'pagos';

    protected $primaryKey = 'id_pago';

    public $timestamps = false;

    protected $fillable = [
        'id_empeno',
        'monto',
        'fecha_pago',
        'metodo_pago'
    ];

    public function empeno()
    {
        return $this->belongsTo(Empeno::class,'id_empeno');
    }

}