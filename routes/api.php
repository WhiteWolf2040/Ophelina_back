<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DashboardController;

/*
|--------------------------------------------------------------------------
| AUTENTICACIÓN
|--------------------------------------------------------------------------
*/

Route::post('/login',[AuthController::class,'login']);


/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS (requieren token)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    ==========================
    USUARIO ACTUAL
    ==========================
    */
    Route::get('/user',[AuthController::class,'user']);

    /*
    ==========================
    CERRAR SESIÓN
    ==========================
    */
    Route::post('/logout',[AuthController::class,'logout']);


    /*
    ==========================
    DASHBOARD
    ==========================
    */
           Route::prefix('dashboard')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);        // Resumen completo
    Route::get('/activos', [DashboardController::class, 'activos']);   // Empeños activos
    Route::get('/vencidos', [DashboardController::class, 'vencidos']); // Empeños vencidos
    Route::get('/proximos', [DashboardController::class, 'proximos']); // Próximos a vencer
    Route::get('/morosidad', [DashboardController::class, 'morosidad']); // Morosidad (pagos atrasados)
      Route::get('/distribucion-categorias', [DashboardController::class, 'distribucionCategorias']);
});

});