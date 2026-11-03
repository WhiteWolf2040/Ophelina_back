<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Programar actualización del precio del oro
Schedule::command('gold:update-price')->dailyAt('08:00');

Schedule::command('empenos:avisar-vencimiento --dias=3')
    ->dailyAt('06:00')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping();




Schedule::command('avisos:empenos')->dailyAt('09:00');

Schedule::command('empenos:avisar-vencimiento')->dailyAt('09:00');
Schedule::command('empenos:avisar-whatsapp --dias=3')->dailyAt('09:00');
Schedule::command('empenos:avisar-whatsapp --dias=0')->dailyAt('09:10');