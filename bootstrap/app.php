<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CheckPermission; 
use Illuminate\Http\Middleware\HandleCors;  

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
         $middleware->api(prepend: [
            HandleCors::class,
        ]);

          $middleware->alias([
        'check.plan' => \App\Http\Middleware\CheckPlanModule::class,
        'check.permission' => CheckPermission::class,]);

        
      
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
